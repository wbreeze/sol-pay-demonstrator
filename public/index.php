<?php

declare(strict_types=1);

/**
 * The front controller. `php -S localhost:8000 -t public` (SPEC §12.0), or any
 * SAPI pointed here.
 *
 * SPEC §12.1's caveat applies to the dev server and is worth knowing before
 * trusting a green test run: `php -S` is single-process by default, so it
 * satisfies §7.2's per-payer serialization for free and therefore *masks* the
 * defect §7.2 exists to prevent. Set PHP_CLI_SERVER_WORKERS, or use a real
 * SAPI, before concluding the two-browsers-one-wallet test passes.
 */

use Newsprint\Auth\Session;
use Newsprint\Auth\SignInException;
use Newsprint\Auth\SignInInput;
use Newsprint\Auth\Verifier;
use Newsprint\Chain\Faucet;
use Newsprint\Chain\PayerReader;
use Newsprint\Chain\PayerState;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\SiteReader;
use Newsprint\Chain\SiteState;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\Submitter;
use Newsprint\Content\Library;
use Newsprint\Content\Piece;
use Newsprint\Support\Alias;
use Newsprint\Support\Config;
use Newsprint\Metering\Meter;
use Newsprint\Metering\MeterMiddleware;
use Newsprint\Metering\MeterOutcome;
use Newsprint\Metering\MeterResult;
use Newsprint\Setup\Provisioner;
use Newsprint\Store\Database;
use Newsprint\Store\Store;
use Newsprint\Support\Inspector;
use Newsprint\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use SolPay\Core\DecodeException;
use SolPay\Core\Shortfall;
use SolPay\Core\Units;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$config = Config::load($root);
$view = new View($root.'/templates');
$contentDir = $root.'/var/content';

$params = $config->siteParams();
$decimals = (int) $params['decimals'];

/**
 * One chain read per request, shared by everything on the page (SPEC §9's
 * inspector is per-request, and §12.4 budgets about three RPC calls per
 * metered view). Memoised so the panel and the price in the copy are the same
 * read rather than two.
 *
 * A failure here is not fatal: an unmetered page owes the chain nothing, so
 * the site keeps serving and the panel says the read failed.
 */
$state = null;
$stateError = null;
$read = static function () use ($config, &$state, &$stateError): ?SiteState {
    static $done = false;
    if ($done) {
        return $state;
    }
    $done = true;

    if (!$config->isProvisioned()) {
        return null;
    }

    try {
        $rpc = new Rpc(
            $config->rpcUrl(),
            $config->program(),
            (string) $config->rpc()['commitment'],
            (int) $config->rpc()['http_timeout_s'],
        );
        $state = (new SiteReader($config, $rpc))->read();
    } catch (RpcException|DecodeException $e) {
        $stateError = $e->getMessage();
    }

    return $state;
};

$panel = new Inspector($config);
$inspector = static function (?PayerState $payer = null) use ($panel, $read, &$stateError): array {
    $state = $read();

    return $panel->sections($state, $stateError, $payer);
};

/**
 * The prices in the reader-facing copy. Claim 7 in §2 is that every number on
 * the screen came from an account, so when the site is provisioned these come
 * from the `Site` account and not from `config/site.php`. The config is the
 * fallback for a copy that has not been set up, and for a chain that cannot be
 * reached.
 */
$siteVars = static function () use ($read, $params, $decimals): array {
    $state = $read();
    $d = $state?->mintDecimals ?? $decimals;

    return [
        'symbol' => (string) $params['symbol'],
        'decimals' => $d,
        'page_price_demo' => Units::fromBaseUnits($state?->site->pagePrice ?? (int) $params['page_price'], $d),
        'min_limit_demo' => Units::fromBaseUnits($state?->site->minLimit ?? (int) $params['min_limit'], $d),
        'threshold_demo' => Units::fromBaseUnits($state?->site->collectionThreshold ?? (int) $params['collection_threshold'], $d),
        'from_chain' => $state !== null,
    ];
};

/**
 * The one SQLite file (§12.5), opened lazily: an unmetered page that never
 * asks who the reader is should not create a database to find out.
 */
$store = static function () use ($config): Store {
    static $store = null;

    return $store ??= new Store(Database::open($config->dbPath()));
};

/**
 * The viewer-to-wallet map (§5), which is the integrator's one obligation and
 * here is a cookie and a row. Null means no paying wallet is stored for this
 * browser, which is the ordinary state of every public page on this site.
 */
$wallet = static function (Request $request) use ($store): ?string {
    $id = Session::idFrom($request);

    return $id === null ? null : $store()->walletForSession($id);
};

$json = static function (Response $response, array $payload, int $status = 200): Response {
    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
};

/**
 * SPEC §12.4: one RPC client, built where it is needed. The endpoint is a
 * config value rather than an architectural one.
 */
$rpcFactory = static function () use ($config): Rpc {
    return new Rpc(
        $config->rpcUrl(),
        $config->program(),
        (string) $config->rpc()['commitment'],
        (int) $config->rpc()['http_timeout_s'],
    );
};

/**
 * `find_contract` from sol-pay's state diagram, run on every article request
 * that has a wallet address to run it with.
 *
 * Note what makes a returning reader work: the contract address is *derived*
 * from the site and the wallet, so a reader who authorized last week and
 * arrives today with an empty cookie jar identifies once and lands on the
 * contract they already have. The session was only ever the map to it, and
 * the site remembers nothing else.
 */
$payerState = static function (Request $request) use ($wallet, $read, $config, $rpcFactory): ?PayerState {
    // One read per request. The meter panel asks, and so does the inspector,
    // and §12.4 budgets about three RPC calls per metered view rather than six.
    static $done = false;
    static $payer = null;
    if ($done) {
        return $payer;
    }
    $done = true;

    $address = $wallet($request);
    $state = $read();
    if ($address === null || $state === null) {
        return null;
    }

    try {
        $payer = (new PayerReader($config, $rpcFactory()))->read($address, $state);
    } catch (RpcException|DecodeException $e) {
        $payer = null;
    }

    return $payer;
};

/**
 * Everything the meter panel draws, in the units the reader sees.
 *
 * The panel replaces the sign-in screen entirely (2026-09-07). sol-pay's state
 * diagram has no sign-in node: `identified` is a choice, not a screen, and
 * "viewer not identified" goes straight to `set_meter`. A separate sign-in
 * page also reads as identification for tracking, which is precisely the thing
 * this site exists to argue against — the identification here is real but
 * narrow, and putting it inside the meter is what makes the narrowness
 * visible.
 */
/**
 * SPEC §7's decision, assembled. Built lazily: a request that is not going
 * to meter should not construct an RPC client and load a keypair to find
 * that out.
 */
$meterFactory = static function () use ($config, $rpcFactory, $store): Meter {
    $rpc = $rpcFactory();

    return new Meter($config, $rpc, new Submitter(
        $rpc,
        (int) $config->rpc()['confirm_timeout_ms'],
        (int) $config->rpc()['confirm_poll_ms'],
    ), $store());
};

$meterVars = static function (Request $request, ?MeterResult $result = null) use ($wallet, $read, $payerState, $config, $store): array {
    $params = $config->siteParams();
    $state = $read();
    $decimals = $state?->mintDecimals ?? (int) $params['decimals'];
    $faucet = $config->faucet();

    $panel = [
        'wallet' => $wallet($request),
        'stage' => 'anonymous',
        'symbol' => (string) $params['symbol'],
        'decimals' => $decimals,
        'balance' => '0',
        'limit_floor' => Units::fromBaseUnits((int) $params['min_limit'], $decimals),
        'views_remaining' => null,
        'blocked' => null,
        'contract' => null,
        'faucet' => [
            'demo' => Units::fromBaseUnits((int) $faucet['demo_base_units'], $decimals),
            'sol' => rtrim(rtrim(number_format((int) $faucet['sol_lamports'] / 1_000_000_000, 9, '.', ''), '0'), '.'),
            'available' => false,
        ],
        'provisioned' => $config->isProvisioned(),
        // The Wallet Standard chain identifier, handed to the panel so the
        // browser can tell a wallet that speaks it from a same-named sibling
        // the same extension registered for another network.
        'chain' => (string) $config->auth()['chain_id'],
        // So the panel can load the wasm client before the reader clicks.
        // Both libraries are fetched while they are choosing a limit, which
        // takes the download out of the window between the blockhash and the
        // wallet dialog — the window §6.3 says is the one that expires.
        'program' => $config->program()->id,
        'token_program' => $config->program()->tokenProgram,
        // What the metering step did, when there was one (§7).
        'result' => $result,
        'page_price' => Units::fromBaseUnits((int) $params['page_price'], $decimals),
        'step_views' => (int) $config->metering()['demo_step_views'],
        // Filled in below when there is a contract to diagnose against.
        'solvency' => null,
    ];

    if ($panel['wallet'] === null) {
        return $panel;
    }

    $panel['faucet']['available'] = !$store()->faucetGranted($panel['wallet']);

    $payer = $payerState($request);
    if ($payer === null) {
        // The chain could not be read. An unmetered page owes it nothing, so
        // the article still serves and the panel says what happened (§9's
        // "a failed read does not take the site down" — which stops being
        // true at the metering path, and should).
        $panel['stage'] = 'unreadable';

        return $panel;
    }

    $panel['balance'] = Units::fromBaseUnits($payer->balance(), $payer->decimals);
    $panel['limit_floor'] = Units::fromBaseUnits($payer->limitFloor(), $payer->decimals);

    if ($payer->hasContract()) {
        $contract = $payer->contract;

        // **What would stop the next settle, asked before it is attempted.**
        //
        // `can_meter` answers a question about the *limit* — whether `used`
        // plus the charge stays under what the reader authorized. It knows
        // nothing about whether the reader can actually pay, because the
        // payment happens inside a `transfer_checked` CPI and SPL is the one
        // that refuses. §8.2 describes reading the token account *after* that
        // refusal; nothing stops the site reading it before, and the
        // difference to a reader is between a button that fails and a button
        // that says why it would.
        //
        // The amount asked about is what a settle would move: the residue
        // already carried, plus what the demo control is about to add.
        $step = (int) $config->metering()['demo_step_views'];
        $wouldMove = $contract->unpaid() + (int) $params['page_price'] * $step;
        $shortfall = $payer->funds === null ? null : Shortfall::diagnose($payer->funds, $wouldMove);
        $panel['solvency'] = $shortfall === null ? null : [
            'would_move' => Units::fromBaseUnits($wouldMove, $decimals),
            'balance_short' => $shortfall->balanceShort,
            'balance_short_demo' => Units::fromBaseUnits($shortfall->balanceShort, $decimals),
            'allowance_short' => $shortfall->allowanceShort,
            'allowance_short_demo' => Units::fromBaseUnits($shortfall->allowanceShort, $decimals),
            'delegate_present' => $shortfall->delegatePresent,
            'clear' => $shortfall->isClear(),
        ];
        $panel['contract'] = [
            'address' => $payer->contractAddress,
            'limit' => Units::fromBaseUnits($contract->limit, $payer->decimals),
            'used' => Units::fromBaseUnits($contract->used, $payer->decimals),
            'paid' => Units::fromBaseUnits($contract->paid, $payer->decimals),
            'unpaid' => Units::fromBaseUnits($contract->unpaid(), $payer->decimals),
        ];
        $panel['views_remaining'] = $payer->viewsRemaining();

        $blocked = $payer->blocked();
        $panel['stage'] = $blocked === null ? 'metered' : 'limit';
        $panel['blocked'] = $blocked === null ? null : (string) $blocked;

        // §8: every branch that leaves the happy path early is a screen, so
        // what the chain actually said outranks what the preflight predicted.
        if ($result !== null) {
            $panel['stage'] = match ($result->outcome) {
                MeterOutcome::Blocked => 'limit',
                MeterOutcome::Failed => 'failed',
                MeterOutcome::Unreadable => 'unreadable',
                default => 'metered',
            };
            if ($result->blocked !== null) {
                $panel['blocked'] = (string) $result->blocked;
            }
        }

        return $panel;
    }

    // Identified, no contract. §4.3's faucet is the branch before `set_meter`,
    // because `approve_checked` against a token account that does not exist
    // fails at the runtime and the reader would never learn why.
    $panel['stage'] = $payer->isFunded() ? 'set-meter' : 'unfunded';

    return $panel;
};

$app = AppFactory::create();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$page = static function (Response $response, string $html, int $status = 200) use ($view, $inspector): Response {
    $response->getBody()->write($html);

    return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

$shell = static function (string $title, string $content, ?PayerState $payer = null) use ($view, $inspector): string {
    return $view->render('layout', [
        'title' => $title,
        'content' => $content,
        // The reader's own accounts appear in the inspector only where a page
        // has already read them. A page with nothing to say about them does
        // not spend an RPC call to say nothing.
        'inspector' => $inspector($payer),
    ]);
};

/**
 * An unknown path is an ordinary thing — a stale link, a browser asking for
 * /favicon.ico — and Slim's default is a stack trace in the log and a bare
 * error page in the browser. Neither belongs on a site whose whole argument is
 * that you can read what it is doing.
 */
$errorMiddleware->setErrorHandler(
    HttpNotFoundException::class,
    static function (Request $request) use ($app, $view, $shell, $page): Response {
        return $page($app->getResponseFactory()->createResponse(), $shell('Not found', $view->render('not-found')), 404);
    },
);

$app->get('/', function (Request $request, Response $response) use ($view, $shell, $page, $contentDir, $siteVars, $config, $wallet): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $articles = Library::load($contentDir)->articles();

    return $page($response, $shell('Newsprint', $view->render('index', [
        'articles' => $articles,
        'site' => $siteVars(),
        'provisioned' => $config->isProvisioned(),
    ])));
});

$article = $app->get('/a/{slug}', function (Request $request, Response $response, array $args) use ($view, $shell, $page, $contentDir, $siteVars, $wallet, $meterVars, $payerState): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $piece = Library::load($contentDir)->find((string) $args['slug']);
    if (!$piece instanceof Piece || !$piece->metered) {
        return $page($response, $shell('Not found', $view->render('not-found')), 404);
    }

    // §7's decision was made by the middleware, before this handler ran, and
    // this is the whole of what the handler does with it. Anything else here —
    // a second read, a "just in case" charge — would be metering per request,
    // which is §7.1's defect.
    $metering = $request->getAttribute(MeterMiddleware::ATTRIBUTE);
    $result = $metering instanceof MeterResult ? $metering : null;
    $body = $result !== null && $result->serves() ? $piece->body() : null;

    // What the seven-view control just did, carried back from its redirect and
    // shown once. Signature-shaped or nothing: a query string is reader-supplied.
    $query = $request->getQueryParams();
    $tx = (string) ($query['tx'] ?? '');
    $outcome = MeterOutcome::tryFrom((string) ($query['advance'] ?? ''));
    $advanced = $outcome === null ? null : [
        'outcome' => $outcome,
        'views' => max(0, min(999, (int) ($query['views'] ?? 0))),
        // Signature-shaped or nothing: a query string is reader-supplied.
        'signature' => preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,90}$/', $tx) === 1 ? $tx : null,
        'settled' => ($query['settled'] ?? '') === '1',
    ];

    return $page($response, $shell($piece->title, $view->render('article', [
        'piece' => $piece,
        'body' => $body,
        'site' => $siteVars(),
        'meter' => $meterVars($request, $result) + ['advanced' => $advanced],
    ]), $payerState($request)));
});

/**
 * §12.1: the metering decision is a middleware, in front of the handler that
 * renders the body. It runs after routing, so the slug is available, and it
 * sets one attribute rather than rendering anything — every branch is a value
 * the handler turns into a screen (§8).
 */
$article->add(new MeterMiddleware(
    static fn (string $slug): ?Piece => Library::isBuilt($contentDir)
        ? Library::load($contentDir)->find($slug)
        : null,
    $wallet,
    $read,
    $meterFactory,
));

/**
 * SPEC §7.4: seven views in one instruction, so the collection threshold is
 * reachable in a handful of clicks rather than fifty page loads.
 *
 * Seven and not ten. Ten is the threshold, so a ten-view step would settle on
 * every click and teach the reader that metering means a transaction per
 * charge — the opposite of what the design is for. Seven settles on some
 * clicks and not others, which is the whole economic argument made visible.
 *
 * It charges honestly. Seven views is seven views, `used` moves by 0.07 DEMO,
 * and the transfer that results is a real transfer. It is not a simulation;
 * it is the same instruction the site would send if the reader had read seven
 * articles, which is exactly why it belongs in a demonstration of the API.
 */
$app->post('/meter/advance', function (Request $request, Response $response) use ($wallet, $read, $config, $meterFactory): Response {
    // A form and a redirect, not JSON and a script. §12.2 puts JavaScript
    // where the wallet is and nowhere else, and this one is signed by the site
    // — the reader's wallet is not involved at all.
    $body = (array) $request->getParsedBody();
    $slug = (string) ($body['slug'] ?? '');
    $back = $slug === '' ? '/' : '/a/'.rawurlencode($slug);

    $address = $wallet($request);
    $state = $read();

    if ($address !== null && $state !== null) {
        $result = $meterFactory()->advance($address, $state, (int) $config->metering()['demo_step_views']);

        // The *state* is not passed back through the URL — the article page
        // re-reads the contract anyway, so a refusal arrives as §8.2's screen
        // rather than as a message about a screen. The **signature** is,
        // because it is the one thing the page cannot re-derive and the site
        // deliberately does not keep.
        //
        // Not stored, and that is the point: §10.4 enumerates this site's
        // stores and says a table added here is a claim on the privacy page.
        // A per-wallet log of metering transactions is precisely the reading
        // history the rest of this design exists to avoid holding, so the
        // signature is handed to the reader once and forgotten. It is public
        // on chain either way; what would be new is *this site* keeping it.
        // **The outcome comes back too, and it has to.** The original version
        // carried only a signature, on the reasoning that the article page
        // re-reads the contract and a refusal would show up in the fresh
        // preflight. That is true of `LimitReached` and false of everything
        // else: a settle that fails leaves the contract exactly as it was, so
        // the re-read says all is well and the click appears to have done
        // nothing at all. `can_meter` is a *limit* check, not a solvency one —
        // §8.2 is explicit that a short balance surfaces from inside the
        // transfer CPI and nowhere earlier.
        $back .= '?advance='.rawurlencode($result->outcome->value)
            .'&views='.$result->pageViews;
        if ($result->signature !== null) {
            $back .= '&tx='.rawurlencode($result->signature)
                .($result->settles ? '&settled=1' : '');
        }
    }

    return $response->withStatus(303)->withHeader('Location', $back);
});

/**
 * SPEC §5, without the screen §5 imagined (2026-09-07). Identifying is three
 * round trips inside the meter panel, and the order is what makes the
 * verifier possible: the server issues fields, the *wallet* builds and signs a
 * message from them, and the server reads back what was actually signed. There
 * is no step in which the server compares the bytes to bytes it composed,
 * because it composed none — which is the property §5 takes on knowingly when
 * it requires `signIn` with no fallback.
 *
 * There is no `GET /signin`. sol-pay's state diagram has no sign-in node —
 * `identified` is a <<choice>>, and "viewer not identified" goes straight to
 * `set_meter` — and a page whose only purpose is to collect an identity reads
 * as identification for tracking, which is the thing this site argues against.
 * The identification here is real but narrow, and it happens inside the panel
 * that is about to spend the reader's money, where its narrowness is visible.
 */
$app->post('/signin/challenge', function (Request $request, Response $response) use ($json, $store, $config): Response {
    $auth = $config->auth();
    $uri = $request->getUri();

    // The domain the wallet will put in the message is the one the browser is
    // looking at, which is the authority — host and port — and not the
    // configured URL. Behind a reverse proxy this is only right if the proxy
    // sets Host; §6.3's HTTPS requirement is where that starts to matter.
    $issued = $store()->issueSignIn(
        static fn (string $nonce): array => SignInInput::issue(
            domain: $uri->getAuthority(),
            uri: (string) $uri->withPath('/signin')->withQuery('')->withFragment(''),
            chainId: (string) $auth['chain_id'],
            statement: (string) $auth['statement'],
            nonce: $nonce,
            now: time(),
            ttlSeconds: (int) $auth['challenge_ttl_s'],
        )->toArray(),
        (int) $auth['challenge_ttl_s'],
    );

    return $json($response, ['input' => $issued['input']]);
});

$app->post('/signin/verify', function (Request $request, Response $response) use ($json, $store, $config): Response {
    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return $json($response, ['message' => 'unreadable request'], 400);
    }

    $nonce = (string) ($body['nonce'] ?? '');
    $address = (string) ($body['address'] ?? '');
    $signedMessage = base64_decode((string) ($body['signedMessage'] ?? ''), true);
    $signature = base64_decode((string) ($body['signature'] ?? ''), true);

    if ($nonce === '' || $address === '' || $signedMessage === false || $signature === false) {
        return $json($response, ['message' => 'incomplete sign-in'], 400);
    }

    // Spent first. A verifier that checks the message and only then marks the
    // nonce used has a window in which the same signature is accepted twice.
    $issuedJson = $store()->consumeSignIn($nonce);
    if ($issuedJson === null) {
        return $json($response, ['message' => 'that sign-in request has expired or was already used; ask for another'], 400);
    }

    $decoded = json_decode($issuedJson, true);
    if (!is_array($decoded) || !isset($decoded['nonce'])) {
        return $json($response, ['message' => 'that sign-in request is unusable; ask for another'], 400);
    }

    try {
        (new Verifier())->verify(
            SignInInput::fromArray($decoded),
            $address,
            $signedMessage,
            $signature,
            time(),
        );
    } catch (SignInException $e) {
        // The nonce is already spent, deliberately: a failed verification does
        // not hand back a challenge to try again against.
        return $json($response, ['message' => $e->getMessage(), 'reason' => $e->reason], 400);
    }

    $id = $store()->createSession($address, (int) $config->auth()['session_ttl_s']);

    // No redirect: the panel is on the page the reader is already reading.
    return Session::issue($json($response, ['ok' => true, 'wallet' => $address]), $id, Session::isSecure($request));
});

/**
 * Forget the paying wallet: the session row and the cookie go, and nothing on
 * chain is touched. §10.4 does this as part of closing a contract, and a
 * reader who wants only the browser end of the §5 mapping dropped — a shared
 * machine, a second wallet, a change of mind before authorizing — is owed it
 * without a transaction.
 *
 * The one caller is the meter (`templates/forget-wallet.php`), which is also
 * where the difference between this and closing is spelled out. It was in the
 * masthead once; see the note in `templates/layout.php` for why it left.
 */
$app->post('/signout', function (Request $request, Response $response) use ($store): Response {
    $id = Session::idFrom($request);
    if ($id !== null) {
        $store()->destroySession($id);
    }

    return Session::clear($response->withStatus(302)->withHeader('Location', '/'), Session::isSecure($request));
});

/**
 * `set_meter` → `authorize`, step two. The server hands over everything the
 * browser needs to build the pair of instructions and compile the message, and
 * fetches the blockhash **here**, immediately before the handoff.
 *
 * That timing is §6.3's point about mobile: a blockhash has to survive an
 * application switch, and one fetched when the page rendered has already spent
 * part of its life. The reader taking thirty seconds in their wallet is the
 * normal case, not the edge one.
 */
$app->post('/meter/prepare', function (Request $request, Response $response) use ($json, $wallet, $read, $payerState, $config, $rpcFactory): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $json($response, ['message' => 'identify first'], 401);
    }

    $state = $read();
    if ($state === null) {
        return $json($response, ['message' => 'this site is not provisioned'], 409);
    }

    $payer = $payerState($request);
    if ($payer === null) {
        return $json($response, ['message' => 'the endpoint did not answer; nothing was signed'], 502);
    }

    $body = json_decode((string) $request->getBody(), true);
    $requested = is_array($body) ? (string) ($body['limit'] ?? '') : '';

    try {
        $limit = Units::toBaseUnits($requested, $payer->decimals);
    } catch (\Throwable $e) {
        return $json($response, ['message' => 'that is not an amount'], 400);
    }

    // The program enforces this and would refuse the transaction, but a reader
    // should not have to open a wallet dialog to be told a number is too
    // small (§4.2, and the diagram's "enforce minimum on limit amount" which
    // sits in `set_meter`, before `authorize`).
    $floor = $payer->limitFloor();
    if ($limit < $floor) {
        return $json($response, [
            'message' => 'the smallest limit you can set is '.Units::fromBaseUnits($floor, $payer->decimals),
        ], 400);
    }

    $addresses = $config->provisioned();
    $program = $config->program();
    $blockhash = $rpcFactory()->latestBlockhash();

    return $json($response, [
        'action' => $payer->hasContract() ? 'renew' : 'open',
        'programAddress' => $program->id,
        'tokenProgram' => $program->tokenProgram,
        'site' => $state->address,
        'mint' => $addresses['mint'],
        'payer' => $payer->wallet,
        'payerTokenAccount' => $payer->tokenAccount,
        'contract' => $payer->contractAddress,
        'decimals' => $payer->decimals,
        // u64 as a string. A JS number loses precision above 2^53 and a
        // payment library that silently truncates is not one anybody can
        // audit — the wasm client crosses these as BigInt for the same reason.
        'limit' => (string) $limit,
        'allowance' => (string) $payer->requiredAllowance($limit),
        'blockhash' => $blockhash['blockhash'],
        'lastValidBlockHeight' => $blockhash['lastValidBlockHeight'] ?? null,
        'chain' => (string) $config->auth()['chain_id'],
    ]);
});

/**
 * The wallet signed and sent it; this is the server finding out whether it
 * landed.
 *
 * The signature is not taken as proof of anything. What is checked is the
 * chain: the contract account this site derives for this reader now exists and
 * says what it should. A signature the browser reports is a claim; an account
 * is a fact.
 */
$app->post('/meter/opened', function (Request $request, Response $response) use ($json, $wallet, $read, $payerState, $rpcFactory, $config): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $json($response, ['message' => 'identify first'], 401);
    }

    $body = json_decode((string) $request->getBody(), true);
    $signature = is_array($body) ? (string) ($body['signature'] ?? '') : '';
    if ($signature === '') {
        return $json($response, ['message' => 'no signature'], 400);
    }

    $rpc = $rpcFactory();
    $deadline = microtime(true) + ((int) $config->rpc()['confirm_timeout_ms']) / 1000;
    $pollMs = (int) $config->rpc()['confirm_poll_ms'];
    $confirmed = false;
    $failed = null;

    while (microtime(true) < $deadline) {
        $status = $rpc->signatureStatuses([$signature])[0] ?? null;
        if ($status !== null) {
            if (($status['err'] ?? null) !== null) {
                $failed = 'the transaction landed and failed';

                break;
            }
            if (in_array($status['confirmationStatus'] ?? '', ['confirmed', 'finalized'], true)) {
                $confirmed = true;

                break;
            }
        }
        usleep($pollMs * 1000);
    }

    if ($failed !== null) {
        return $json($response, ['message' => $failed], 409);
    }

    $payer = $payerState($request);
    if ($payer !== null && $payer->hasContract()) {
        return $json($response, ['ok' => true, 'confirmed' => $confirmed]);
    }

    // §7.3's shape: sent, not confirmed inside the window, and the account is
    // not there yet. Not an error and not a success — the reader reloads and
    // the chain answers.
    return $json($response, [
        'ok' => false,
        'pending' => true,
        'message' => 'sent, but the contract is not on chain yet; reload in a moment',
    ], 202);
});

/**
 * `manage_meter` (§6). Reachable at any time, not only at the limit — decided
 * 2026-09-02, reversing an earlier decision, because a reader who has
 * authorized a site to draw from their wallet may reasonably expect to find,
 * at any moment and without exhausting anything first, a page that says what
 * they have spent and offers a way out. Making them hit a limit to reach the
 * exit is not a defensible product, whatever the state diagram omits.
 */
$app->get('/meter', function (Request $request, Response $response) use ($view, $shell, $page, $wallet, $payerState, $read, $store, $config, $siteVars): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $page($response, $shell('The meter', $view->render('manage-meter', [
            'stage' => 'anonymous',
            'site' => $siteVars(),
        ])));
    }

    $payer = $payerState($request);
    $state = $read();
    $params = $config->siteParams();
    $decimals = $payer?->decimals ?? ($state?->mintDecimals ?? (int) $params['decimals']);

    if ($payer === null) {
        return $page($response, $shell('The meter', $view->render('manage-meter', [
            'stage' => 'unreadable',
            'site' => $siteVars(),
        ])));
    }

    if (!$payer->hasContract()) {
        return $page($response, $shell('The meter', $view->render('manage-meter', [
            'stage' => 'no-contract',
            'site' => $siteVars(),
            'wallet' => $address,
        ]), $payer));
    }

    $contract = $payer->contract;
    $blocked = $payer->blocked();

    return $page($response, $shell('The meter', $view->render('manage-meter', [
        'stage' => 'open',
        'site' => $siteVars(),
        'wallet' => $address,
        'chain' => (string) $config->auth()['chain_id'],
        'program' => $config->program()->id,
        'token_program' => $config->program()->tokenProgram,
        'symbol' => (string) $params['symbol'],
        'contract' => [
            'address' => $payer->contractAddress,
            'limit' => Units::fromBaseUnits($contract->limit, $decimals),
            'used' => Units::fromBaseUnits($contract->used, $decimals),
            'paid' => Units::fromBaseUnits($contract->paid, $decimals),
            'unpaid' => Units::fromBaseUnits($contract->unpaid(), $decimals),
        ],
        'views_remaining' => $payer->viewsRemaining(),
        'blocked' => $blocked === null ? null : (string) $blocked,
        'limit_floor' => Units::fromBaseUnits($payer->limitFloor(), $decimals),
        'balance' => Units::fromBaseUnits($payer->balance(), $decimals),
        // The delegate, which is the whole of what authorizing gave away, and
        // which a wallet will show a balance without ever mentioning.
        'token_account' => $payer->tokenAccount,
        'delegate' => $payer->funds?->delegate,
        'approved' => Units::fromBaseUnits($payer->funds?->delegatedAmount ?? 0, $decimals),
        // §10.4 qualification 2: the reader is told what closing costs them
        // *before* they click, and told the true number rather than "an
        // article".
        'live_grants' => $store()->liveGrantCount($address),
    ]), $payer));
});

/**
 * `close_and_revoke`, prepared. Two instructions and no arguments — there is
 * nothing to choose, which is why this endpoint takes no body.
 */
$app->post('/meter/close/prepare', function (Request $request, Response $response) use ($json, $wallet, $read, $payerState, $config, $rpcFactory): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $json($response, ['message' => 'identify first'], 401);
    }

    $state = $read();
    $payer = $payerState($request);
    if ($state === null || $payer === null) {
        return $json($response, ['message' => 'the endpoint did not answer; nothing was signed'], 502);
    }
    if (!$payer->hasContract()) {
        return $json($response, ['message' => 'there is no contract to close'], 409);
    }

    $program = $config->program();
    $blockhash = $rpcFactory()->latestBlockhash();

    return $json($response, [
        'action' => 'close',
        'programAddress' => $program->id,
        'tokenProgram' => $program->tokenProgram,
        'site' => $state->address,
        'payer' => $payer->wallet,
        'payerTokenAccount' => $payer->tokenAccount,
        'contract' => $payer->contractAddress,
        'blockhash' => $blockhash['blockhash'],
        'lastValidBlockHeight' => $blockhash['lastValidBlockHeight'] ?? null,
        'chain' => (string) $config->auth()['chain_id'],
    ]);
});

/**
 * The chain confirmed it; now §10.4 runs.
 *
 * The order matters and it is the reverse of the metering path's. Here the
 * site waits for the chain **before** deleting anything, because a purge on
 * the strength of an unconfirmed transaction would erase a reader whose
 * contract is still open and still spending. The proof is the account: the
 * contract PDA is gone, which no report from a browser could establish.
 */
$app->post('/meter/close/done', function (Request $request, Response $response) use ($json, $wallet, $payerState, $rpcFactory, $store, $config): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $json($response, ['message' => 'identify first'], 401);
    }

    $body = json_decode((string) $request->getBody(), true);
    $signature = is_array($body) ? (string) ($body['signature'] ?? '') : '';
    if ($signature === '') {
        return $json($response, ['message' => 'no signature'], 400);
    }

    $rpc = $rpcFactory();
    $deadline = microtime(true) + ((int) $config->rpc()['confirm_timeout_ms']) / 1000;
    $pollMs = (int) $config->rpc()['confirm_poll_ms'];
    $confirmed = false;

    while (microtime(true) < $deadline) {
        $status = $rpc->signatureStatuses([$signature])[0] ?? null;
        if ($status !== null) {
            if (($status['err'] ?? null) !== null) {
                return $json($response, ['message' => 'the transaction landed and failed; nothing was deleted'], 409);
            }
            if (in_array($status['confirmationStatus'] ?? '', ['confirmed', 'finalized'], true)) {
                $confirmed = true;

                break;
            }
        }
        usleep($pollMs * 1000);
    }

    $payer = $payerState($request);
    if ($payer === null || $payer->hasContract()) {
        // Sent, and the account is still there. Nothing is deleted on a maybe.
        return $json($response, [
            'ok' => false,
            'pending' => true,
            'message' => 'sent, but the contract is still on chain; reload in a moment and close again if it is still here',
        ], 202);
    }

    // §10.4. Session, grants and the lock row go; the faucet ledger survives
    // for the published reason.
    $erased = $store()->eraseReader($address);
    $id = Session::idFrom($request);
    if ($id !== null) {
        $store()->destroySession($id);
    }

    return Session::clear($json($response, [
        'ok' => true,
        'confirmed' => $confirmed,
        'signature' => $signature,
        'erased' => $erased,
        // Read back from the token account after the close, not asserted: this
        // is the line claim 6 in §2 is actually about, and it is the one a
        // wallet is least likely to show the reader itself.
        'delegate' => $payer->funds?->delegate,
        'tokenAccount' => $payer->tokenAccount,
    ]), Session::isSecure($request));
});

/**
 * SPEC §4.3. The site signs this one, so it is a button and not a wallet
 * interaction — nothing here spends the reader's money.
 */
$app->post('/faucet', function (Request $request, Response $response) use ($json, $wallet, $store, $config, $rpcFactory): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $json($response, ['message' => 'identify first'], 401);
    }
    if (!$config->isProvisioned()) {
        return $json($response, ['message' => 'this site is not provisioned'], 409);
    }

    $rpc = $rpcFactory();
    $result = (new Faucet($config, new Submitter(
        $rpc,
        (int) $config->rpc()['confirm_timeout_ms'],
        (int) $config->rpc()['confirm_poll_ms'],
    ), $store()))->grant($address);

    return $json($response, $result, $result['granted'] ? 200 : 409);
});

/** §10.2: the site carries a page at the URL a privacy policy would occupy. */
$app->get('/privacy', function (Request $request, Response $response) use ($view, $shell, $page, $contentDir): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $piece = Library::load($contentDir)->find('privacy');
    if (!$piece instanceof Piece || $piece->metered) {
        return $page($response, $shell('Not found', $view->render('not-found')), 404);
    }

    return $page($response, $shell($piece->title, $view->render('page', [
        'piece' => $piece,
        'body' => $piece->body(),
    ])));
});

/**
 * SPEC §12.0 and §3: first-run setup is a screen. It is the only worked example
 * of `initialize_site` anywhere, and the separation an operator CLI would have
 * given is enforced in the code instead — the provisioner refuses to run
 * against a site that already exists.
 */
$provisioner = static function () use ($config): Provisioner {
    $rpc = new Rpc(
        $config->rpcUrl(),
        $config->program(),
        (string) $config->rpc()['commitment'],
        (int) $config->rpc()['http_timeout_s'],
    );

    return new Provisioner($config, $rpc, new Submitter(
        $rpc,
        (int) $config->rpc()['confirm_timeout_ms'],
        (int) $config->rpc()['confirm_poll_ms'],
    ));
};

$app->get('/setup', function (Request $request, Response $response) use ($view, $shell, $page, $provisioner, $config, $siteVars): Response {
    $error = null;
    $status = ['provisioned' => $config->isProvisioned(), 'authority' => '', 'faucet' => '', 'balance' => 0, 'needed' => 0, 'funded' => false];

    try {
        $status = $provisioner()->status();
    } catch (RpcException $e) {
        // The endpoint being unreachable is an ordinary thing on a laptop, and
        // a stack trace is a poor way to say so.
        $error = $e->getMessage();
    }

    return $page($response, $shell('First run', $view->render('setup', [
        'status' => $status,
        'site' => $siteVars(),
        'setup' => $config->setup(),
        'error' => $error,
    ])));
});

$app->post('/setup', function (Request $request, Response $response) use ($view, $shell, $page, $provisioner, $root): Response {
    $steps = $provisioner()->run();

    // Re-read from disk: the provisioner wrote var/site.json as it went, and
    // the config this request started with predates that.
    $provisioned = Config::load($root)->isProvisioned();

    return $page($response, $shell($provisioned ? 'Provisioned' : 'Setup stopped', $view->render('setup-ran', [
        'steps' => $steps,
        'provisioned' => $provisioned,
    ])));
});

/**
 * A workbench, not a screen (§6 lists five and this is none of them).
 *
 * Claude reasons from specifications and cannot open a browser; this is where
 * the browser answers back. `GET` renders the page, `POST` lands what it found
 * in `var/`, which Claude can read and which git ignores.
 */
$app->get('/diagnostics/wallets', function (Request $request, Response $response) use ($view, $shell, $page, $wallet): Response {
    return $page($response, $shell('Wallet diagnostics', $view->render('diagnostics')));
});

$app->post('/diagnostics/report', function (Request $request, Response $response) use ($json, $config): Response {
    $body = (string) $request->getBody();
    if ($body === '' || json_decode($body) === null) {
        return $json($response, ['message' => 'unreadable report'], 400);
    }

    $dir = $config->root.'/var/wallet-reports';
    if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
        return $json($response, ['message' => 'could not create var/wallet-reports'], 500);
    }

    $name = gmdate('Ymd-His').'.json';
    if (file_put_contents($dir.'/'.$name, $body."\n") === false) {
        return $json($response, ['message' => 'could not write the report'], 500);
    }

    return $json($response, ['path' => 'var/wallet-reports/'.$name]);
});

/** Operator-facing, and deliberately not keyed to any reader (§10.4). */
$app->get('/health', function (Request $request, Response $response) use ($config, $contentDir): Response {
    $payload = [
        'php' => PHP_VERSION,
        'extensions' => [
            'sodium' => extension_loaded('sodium'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'curl' => extension_loaded('curl'),
        ],
        'rpc' => $config->rpcUrl(),
        'program' => $config->program()->id,
        'provisioned' => $config->isProvisioned(),
        'content_built' => Library::isBuilt($contentDir),
    ];

    // Costs one RPC call, and answers the question that otherwise surfaces as
    // "Attempt to load a program that does not exist" halfway through setup.
    try {
        $payload['program_deployed'] = (new Rpc(
            $config->rpcUrl(),
            $config->program(),
            (string) $config->rpc()['commitment'],
            (int) $config->rpc()['http_timeout_s'],
        ))->programDeployed($config->program()->id);
    } catch (RpcException $e) {
        $payload['program_deployed'] = null;
        $payload['rpc_error'] = $e->getMessage();
    }

    $response->getBody()->write(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
