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
use Newsprint\Chain\ProgramEvent;
use Newsprint\Chain\RequestRead;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\Submitter;
use Newsprint\Content\Library;
use Newsprint\Content\Piece;
use Newsprint\Support\Alias;
use Newsprint\Support\Config;
use Newsprint\Support\RpcTimingMiddleware;
use Newsprint\Metering\Decision;
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
 * Everything this request knows about the chain, read once.
 *
 * SPEC §12.4 asks for exactly this — "fetch the `Site`, `Contract` and payer
 * token account **in one round trip**" — and until 2026-09-10 the code did it
 * in two, because {@see RequestRead} did not exist and the site read and the
 * payer read were separate closures that happened to run in that order. A HAR
 * that morning put the median round trip at 1242 ms, so the second one was not
 * a rounding error.
 *
 * `find_contract` from sol-pay's state diagram lives in there now, beside the
 * site read it used to follow. Note what still makes a returning reader work:
 * the contract address is *derived* from the site and the wallet, so a reader
 * who authorized last week and arrives today with an empty cookie jar
 * identifies once and lands on the contract they already have. The session was
 * only ever the map to it, and the site remembers nothing else. That
 * derivation is also why the two reads could be merged at all — neither of the
 * payer's addresses was ever waiting on the site *account*.
 *
 * The wallet is settled before the object is built, from the cookie and the
 * store, which costs no round trip. That is what lets one call cover five
 * accounts instead of three: the request knows who is reading before it knows
 * anything about the chain.
 *
 * Three by-reference variables and three closures used to live here. One of
 * them, `$stateAlreadyRead`, was written `function` rather than `fn` because an
 * arrow function would have captured `false` for the life of the request —
 * `FrontControllerTest` guards that shape, and it is no accident that the
 * front controller is where it kept happening.
 */
$reads = static function (Request $request) use ($config, $rpcFactory, $wallet): RequestRead {
    static $reads = null;

    return $reads ??= new RequestRead($config, $rpcFactory(), $wallet($request));
};

$panel = new Inspector($config);

/**
 * The panel's sections, or **null to say "not on this request"** (2026-09-09).
 *
 * §9 says the inspector is collapsed by default, and it is rendered on every
 * page by `$shell`. Until now that meant every page — `/privacy` included —
 * blocked on one `getMultipleAccounts` to fill a panel most readers never
 * open. Measured 2026-09-09: that call *was* `/privacy`, 0.9 s of it, on a
 * page which displays nothing from the chain.
 *
 * So the rule is: render the panel when this request has already read the
 * chain for its own reasons, and defer it when it has not.
 *
 * **That rule is what protects §9's last section**, and it is why the test is
 * "did something already read" rather than "is this page cheap". The last
 * transaction needs a `MeterResult` that exists only on the request that
 * produced it — §10.4 leaves no history for a second request to find — so it
 * could never survive a deferred fetch. It does not have to: on the article's
 * POST `MeterMiddleware` has already read the chain to make the metering
 * decision, so the state is in hand, the panel renders with the answer, and the
 * read costs nothing extra because it was required work either way. (The
 * article's GET shell defers the panel like `/privacy` does, and the POST's
 * answer replaces it — see `assets/read-on.js`.)
 *
 * `$payer` and `$result` are checked too, belt and braces: either one means a
 * caller has request-scoped data the deferred route could not reconstruct.
 */
$inspector = static function (?RequestRead $reads, ?MeterResult $result = null) use ($panel): ?array {
    // One condition where there were three. `$payer !== null` used to be
    // checked beside this, belt and braces, and it was always implied: reading
    // a payer goes through the same object as reading the site, so a request
    // holding one has read. `$reads === null` is the shape of a page that
    // never asked for the object at all.
    if ($reads === null || (!$reads->hasRead() && $result === null)) {
        return null;
    }

    return $panel->sections($reads->site(), $reads->error(), $reads->payer(), $result);
};

/**
 * The prices in the reader-facing copy. Claim 7 in §2 is that every number on
 * the screen came from an account, so when the site is provisioned these come
 * from the `Site` account and not from `config/site.php`. The config is the
 * fallback for a copy that has not been set up, and for a chain that cannot be
 * reached.
 */
$siteVars = static function (RequestRead $reads) use ($params, $decimals): array {
    $state = $reads->site();
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

$meterVars = static function (Request $request, ?MeterResult $result = null) use ($reads, $config, $store): array {
    $params = $config->siteParams();
    $read = $reads($request);
    $state = $read->site();
    $decimals = $state?->mintDecimals ?? (int) $params['decimals'];
    $faucet = $config->faucet();

    $panel = [
        'wallet' => $read->wallet(),
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

    $payer = $read->payer();
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

$page = static function (Response $response, string $html, int $status = 200): Response {
    $response->getBody()->write($html);

    return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

$shell = static function (string $title, string $content, ?RequestRead $reads = null, ?MeterResult $result = null) use ($view, $inspector): string {
    return $view->render('layout', [
        'title' => $title,
        'content' => $content,
        // The reader's own accounts appear in the inspector only where a page
        // has already read them. A page with nothing to say about them does
        // not spend an RPC call to say nothing.
        //
        // §9's last section is narrower still: it needs a transaction *this
        // request* produced, which is the article's POST and nowhere else. §10.4
        // leaves no stored history for any other page to show.
        'inspector' => $inspector($reads, $result),
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

$app->get('/', function (Request $request, Response $response) use ($view, $shell, $page, $contentDir, $siteVars, $config, $reads): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $articles = Library::load($contentDir)->articles();

    return $page($response, $shell('Newsprint', $view->render('index', [
        'articles' => $articles,
        'site' => $siteVars($reads($request)),
        'provisioned' => $config->isProvisioned(),
    ]), $reads($request)));
});

/**
 * The article as a reader sees it once the metering question is answered: the
 * lede, then the body or the meter.
 *
 * Shared by the two article routes — the GET, which answers from a grant or
 * has nobody to charge, and the POST, which charges — so the one state cannot
 * be rendered two ways depending on which request happened to produce it.
 */
$articleContent = static function (Request $request, Piece $piece, ?MeterResult $result) use ($view, $siteVars, $meterVars, $reads): string {
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

    return $view->render('article', [
        'piece' => $piece,
        'body' => $body,
        'site' => $siteVars($reads($request)),
        'meter' => $meterVars($request, $result) + ['advanced' => $advanced],
    ]);
};

/**
 * SPEC §7.1: **a GET never meters** (2026-09-11). Charging is the POST below.
 *
 * This route answers one of three ways, and decides which without asking the
 * chain anything — the wallet comes from the cookie and the store, and the
 * grant is a row:
 *
 * - **A reader holding a live grant** gets the article, whole. §7.1 says a
 *   request that finds a live grant is served without touching the chain, and
 *   the decision here does not; the one read on this page is the meter strip's
 *   arithmetic, which claim 7 says must come from an account.
 * - **A reader the site could charge** — a wallet, a metered piece, no grant —
 *   gets the *shell*: the lede, and a form that posts to this same URL. It is
 *   rendered from nothing but the content index, so it arrives in the time it
 *   takes to send it, and `assets/read-on.js` posts the form at once and puts
 *   the answer where the form was. The three to ten seconds of validator that
 *   used to pass with the old page on screen now pass with the new one, saying
 *   what it is waiting for. Without JavaScript the form is a button.
 * - **Anybody else** — no wallet, an unmetered piece, a copy not set up — gets
 *   the lede and the meter, exactly as before.
 *
 * The shell does not know whether the POST will charge, set a meter, or stop
 * at a limit, because knowing would cost the read the shell exists to avoid.
 * So it claims nothing the chain would have to answer — not even the price,
 * which comes from the `Site` account and arrives with the rest.
 */
$app->get('/a/{slug}', function (Request $request, Response $response, array $args) use ($view, $shell, $page, $contentDir, $articleContent, $reads, $wallet, $store, $config): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $piece = Library::load($contentDir)->find((string) $args['slug']);
    if (!$piece instanceof Piece || !$piece->metered) {
        return $page($response, $shell('Not found', $view->render('not-found')), 404);
    }

    $address = $wallet($request);
    $result = null;

    if ($address !== null && $config->isProvisioned() && Decision::shouldMeter($piece, $address)) {
        if ($store()->liveGrant($address, $piece->slug) === null) {
            // No `$reads`: the inspector is deferred exactly as it is on
            // `/privacy`, and the POST's answer fills it.
            return $page($response, $shell($piece->title, $view->render('article-pending', [
                'piece' => $piece,
                'longWaitMs' => (int) $config->metering()['long_wait_ms'],
            ])));
        }

        $result = MeterResult::granted();
    }

    return $page($response, $shell($piece->title, $articleContent($request, $piece, $result), $reads($request), $result));
});

/**
 * SPEC §7: the page view. `MeterMiddleware` has made the metering decision by
 * the time this runs, and a live grant found inside §7.2's lock makes a second
 * POST — a double click, a retry after a lost response, a second tab — free.
 *
 * Two answers from one handler, and the difference is only packaging:
 *
 * - **`X-Fragment: 1`** — what `assets/read-on.js` asks for. The article, then
 *   the inspector, rendered by this request from the `MeterResult` this request
 *   produced, which is the only place §9's last-transaction section can come
 *   from (§10.4 leaves no history for a later request to find). The script
 *   swaps both in together, so nothing on screen predates the charge — the
 *   stale-inspector problem that sank an in-place re-render of the advance
 *   does not arise, because the shell carries nothing a charge can change.
 * - **Anything else** — the form submitted without JavaScript. The whole page,
 *   rendered directly rather than redirected: a redirect would land on the GET,
 *   find the grant and report "served from a grant you already hold", losing
 *   the charge's report and its transaction on the way. A refresh of this page
 *   asks to resubmit, and resubmitting finds the grant.
 *
 * `no-store` on both, for the inspector fragment's reason: these are account
 * values read on this request, and a cached copy would be the server's memory
 * answering for the chain.
 */
$articlePost = $app->post('/a/{slug}', function (Request $request, Response $response, array $args) use ($view, $shell, $page, $contentDir, $articleContent, $inspector, $reads): Response {
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
    $content = $articleContent($request, $piece, $result);

    if ($request->getHeaderLine('X-Fragment') === '1') {
        $response->getBody()->write($content.$view->render('inspector', [
            'sections' => $inspector($reads($request), $result),
        ]));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    return $page($response, $shell($piece->title, $content, $reads($request), $result))
        ->withHeader('Cache-Control', 'no-store');
});

/**
 * §12.1: the metering decision is a middleware, in front of the handler that
 * renders the body. It runs after routing, so the slug is available, and it
 * sets one attribute rather than rendering anything — every branch is a value
 * the handler turns into a screen (§8). On the POST and only the POST: see the
 * middleware's own docblock for why the GET has none.
 */
$articlePost->add(new MeterMiddleware(
    static fn (string $slug): ?Piece => Library::isBuilt($contentDir)
        ? Library::load($contentDir)->find($slug)
        : null,
    $reads,
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
$app->post('/meter/advance', function (Request $request, Response $response) use ($wallet, $reads, $config, $meterFactory): Response {
    // A form and a redirect, not JSON and a script: this one is signed by the
    // site and the reader's wallet is not involved at all. `assets/advance.js`
    // only disables the button and says the advance is under way while the
    // validator answers; without it the form works exactly the same.
    $body = (array) $request->getParsedBody();
    $slug = (string) ($body['slug'] ?? '');
    $back = $slug === '' ? '/' : '/a/'.rawurlencode($slug);

    $address = $wallet($request);
    $state = $reads($request)->site();

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
$app->post('/meter/prepare', function (Request $request, Response $response) use ($json, $wallet, $reads, $config, $rpcFactory): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $json($response, ['message' => 'identify first'], 401);
    }

    $state = $reads($request)->site();
    if ($state === null) {
        return $json($response, ['message' => 'this site is not provisioned'], 409);
    }

    $payer = $reads($request)->payer();
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
$app->post('/meter/opened', function (Request $request, Response $response) use ($json, $wallet, $reads, $rpcFactory, $config): Response {
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

    // The wallet's `approve_and_open` has landed by now, so anything this
    // request read before the poll is out of date. It read nothing — the
    // read below is this route's first — and saying so anyway is what keeps
    // that true if someone later adds an earlier one.
    $reads($request)->invalidatePayer();

    $payer = $reads($request)->payer();
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
$app->get('/meter', function (Request $request, Response $response) use ($view, $shell, $page, $wallet, $reads, $store, $config, $siteVars): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $page($response, $shell('The meter', $view->render('manage-meter', [
            'stage' => 'anonymous',
            'site' => $siteVars($reads($request)),
        ]), $reads($request)));
    }

    $payer = $reads($request)->payer();
    $state = $reads($request)->site();
    $params = $config->siteParams();
    $decimals = $payer?->decimals ?? ($state?->mintDecimals ?? (int) $params['decimals']);

    if ($payer === null) {
        return $page($response, $shell('The meter', $view->render('manage-meter', [
            'stage' => 'unreadable',
            'site' => $siteVars($reads($request)),
        ]), $reads($request)));
    }

    if (!$payer->hasContract()) {
        return $page($response, $shell('The meter', $view->render('manage-meter', [
            'stage' => 'no-contract',
            'site' => $siteVars($reads($request)),
            'wallet' => $address,
        ]), $reads($request)));
    }

    $contract = $payer->contract;
    $blocked = $payer->blocked();

    return $page($response, $shell('The meter', $view->render('manage-meter', [
        'stage' => 'open',
        'site' => $siteVars($reads($request)),
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
    ]), $reads($request)));
});

/**
 * `close_and_revoke`, prepared. Two instructions and no arguments — there is
 * nothing to choose, which is why this endpoint takes no body.
 */
$app->post('/meter/close/prepare', function (Request $request, Response $response) use ($json, $wallet, $reads, $config, $rpcFactory): Response {
    $address = $wallet($request);
    if ($address === null) {
        return $json($response, ['message' => 'identify first'], 401);
    }

    $state = $reads($request)->site();
    $payer = $reads($request)->payer();
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
$app->post('/meter/close/done', function (Request $request, Response $response) use ($json, $wallet, $reads, $rpcFactory, $store, $config): Response {
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

    // §10.4's ordering: the account is the evidence, not the signature, so
    // this has to be a reading taken after the close confirmed.
    $reads($request)->invalidatePayer();

    $payer = $reads($request)->payer();
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

$app->get('/setup', function (Request $request, Response $response) use ($view, $shell, $page, $provisioner, $config, $siteVars, $reads): Response {
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
        'site' => $siteVars($reads($request)),
        'setup' => $config->setup(),
        'error' => $error,
    ]), $reads($request)));
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
/**
 * The panel's sections, for a page that did not read the chain (2026-09-09).
 *
 * Two shapes from one URL, and the reason is that the panel must not need
 * JavaScript to exist:
 *
 *   · asked with `X-Fragment: 1` — what `assets/inspector.js` sends on first
 *     open — it answers with the sections markup alone, which the script puts
 *     where the deferred paragraph was;
 *   · asked by a browser following the link in that paragraph, it answers with
 *     an ordinary page. The read is performed first, so `$shell` finds the state
 *     already in hand and renders the panel inline, exactly as the article
 *     route does. A reader with no JavaScript clicks a link and gets the panel;
 *     nothing about §9 depends on a script.
 *
 * It is one route rather than two because the sections come from one partial
 * either way. Two routes would be two chances for the fragment and the page to
 * drift apart.
 */
$app->get('/inspector/panel', function (Request $request, Response $response) use ($view, $shell, $page, $panel, $reads): Response {
    if ($request->getHeaderLine('X-Fragment') === '1') {
        $sections = $panel->sections($reads($request)->site(), $reads($request)->error());

        $response->getBody()->write($view->render('inspector-sections', [
            'sections' => $sections,
            'view' => $view,
        ]));

        // `no-store`: these are account values read on this request, and a
        // cached fragment would be the server's memory answering for the
        // chain — which is the one thing §2's claim 7 says this panel never
        // does.
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    // Not a fragment: read first, so `$shell` renders the panel inline below.
    $reads($request)->site();

    return $page($response, $shell(
        'Inspector',
        '<p class="lede">The inspector is below, with the accounts read on this'
            .' request. Every other page defers this read until you open it.</p>',
        $reads($request),
    ));
});

/**
 * SPEC §9's last section, read on demand.
 *
 * The panel renders the signature and the instruction bytes with the page,
 * because the server already had both. The decoded event needs
 * `getTransaction`, which would be a fourth RPC call on a request §12.4
 * budgets about three for — and spent on every metered view whether or not
 * anybody expands a panel §9 says is collapsed by default. So it is here, and
 * `assets/inspector.js` asks for it the first time the panel is opened.
 *
 * Nothing about this endpoint is privileged and it deliberately holds no
 * session: a transaction signature is public, and every byte this returns is
 * readable by anyone with the same signature and an explorer. What it is not
 * is a lookup of *your* history — it answers about the one signature it is
 * given, and §10.4 leaves this site with no list to hand out.
 */
$app->get('/inspector/event/{signature}', function (Request $request, Response $response, array $args) use ($json, $rpcFactory, $config): Response {
    $signature = (string) $args['signature'];

    // Base58 has no 0, O, I or l; a signature is 64 raw bytes, which encodes
    // to 86 to 88 characters. Checked here so an obviously malformed value
    // costs a regex rather than a round trip to the endpoint.
    if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,90}$/', $signature) !== 1) {
        return $json($response, ['text' => 'that is not a transaction signature'], 400);
    }

    if (!$config->isProvisioned()) {
        return $json($response, ['text' => 'this copy is not provisioned']);
    }

    try {
        $logs = $rpcFactory()->transactionLogs($signature);
    } catch (RpcException $e) {
        // §8.1 again: the endpoint's own message, not the transaction's logs.
        return $json($response, ['text' => 'the endpoint did not answer: '.$e->getMessage()]);
    }

    if ($logs === null) {
        return $json($response, ['text' => 'the cluster does not have this transaction yet — try again in a moment']);
    }

    $event = ProgramEvent::fromLogs($logs);
    if ($event === null) {
        return $json($response, ['text' => 'no '.implode(', ', ProgramEvent::known()).' event in this transaction']);
    }

    $decimals = (int) $config->siteParams()['decimals'];
    $symbol = (string) $config->siteParams()['symbol'];

    $parts = [];
    foreach ($event->fields as $field => $value) {
        $parts[] = $field.' '.match (true) {
            // The two counts are counts. Everything else is a token amount and
            // gets both unit forms, for the reason §9 gives about the panel
            // generally: a six-decimal scaling error is invisible in one form.
            //
            // The contract carries its alias, because every other address in
            // the panel does and an event line that showed a bare base58 would
            // be the one place a reader had to match 44 characters by eye.
            // `Alias::for` is a hash of the address, so this needs no state.
            $field === 'contract' => Alias::for(Alias::CONTRACT, (string) $value).'  '.$value,
            $field === 'page_views' => (string) $value,
            default => sprintf('%s %s (%d)', Units::fromBaseUnits((int) $value, $decimals), $symbol, (int) $value),
        };
    }

    return $json($response, ['text' => $event->name.' — '.implode(' · ', $parts)]);
});

$app->get('/diagnostics/wallets', function (Request $request, Response $response) use ($view, $shell, $page): Response {
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

/**
 * Outermost, deliberately: added last, so in Slim it wraps every other
 * middleware and every route, and the `X-Rpc-*` headers land on redirects too
 * — including the 303 out of `/meter/advance`, which is the response that
 * carries the metering transaction's round trips.
 *
 * Silent unless `NEWSPRINT_RPC_TIMING=1` is in the environment. See
 * {@see RpcTimingMiddleware} for why environment and not config.
 */
$app->add(new RpcTimingMiddleware());

$app->run();
