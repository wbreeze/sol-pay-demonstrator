<?php

declare(strict_types=1);

/**
 * The front controller. `php -S localhost:8000 -t public` (SPEC §12.0), or any
 * SAPI pointed here.
 *
 * SPEC §12.1's caveat applies to the dev server and is worth knowing before
 * trusting a green test run: `php -S` is single-process by default, so it
 * satisfies §7.2's per-meter serialization for free and therefore *masks* the
 * defect §7.2 exists to prevent. Set PHP_CLI_SERVER_WORKERS, or use a real
 * SAPI, before concluding that two overlapping requests on one meter charge
 * once.
 */

use Newsprint\Auth\Binding;
use Newsprint\Auth\KeyProof;
use Newsprint\Auth\Session;
use Newsprint\Chain\Keypair;
use Newsprint\Chain\ProgramEvent;
use Newsprint\Chain\RequestRead;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\SubmitStatus;
use Newsprint\Chain\Submitter;
use Newsprint\Content\Library;
use Newsprint\Content\Piece;
use Newsprint\Support\Alias;
use Newsprint\Support\Config;
use Newsprint\Support\RpcTimingMiddleware;
use Newsprint\Metering\ChargeFollowUp;
use Newsprint\Metering\ChargeState;
use Newsprint\Metering\CloseFinisher;
use Newsprint\Metering\Decision;
use Newsprint\Metering\Meter;
use Newsprint\Metering\MeterMiddleware;
use Newsprint\Metering\MeterClose;
use Newsprint\Metering\MeterOutcome;
use Newsprint\Metering\MeterResult;
use Newsprint\Setup\Provisioner;
use Newsprint\Setup\SameOrigin;
use Newsprint\Setup\Step;
use Newsprint\Store\Database;
use Newsprint\Store\Store;
use Newsprint\Support\Inspector;
use Newsprint\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use SolPay\Core\BlockedKind;
use SolPay\Core\DecodeException;
use SolPay\Core\Fund;
use SolPay\Core\Meter as OnChainMeter;
use SolPay\Core\PayError;
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
 * SPEC §10.4 qualification 2: finish an erasure whose close landed after the
 * close route stopped waiting. See {@see CloseFinisher}.
 *
 * True when this call erased the meter's record. With no pending close for
 * the meter, the answer is one indexed read and no chain call. With one, a
 * fresh read of the meter account decides, and that read is made once per
 * meter per request, since `$binding` is asked more than once.
 */
$meterExists = static function (string $meter) use ($rpcFactory): ?bool {
    try {
        return $rpcFactory()->accountExists($meter);
    } catch (RpcException) {
        return null;
    }
};

$finishClose = static function (string $meter) use ($store, $config, $meterExists): bool {
    static $answered = [];

    if (!array_key_exists($meter, $answered)) {
        $finisher = new CloseFinisher($store(), (int) $config->metering()['close_settle_s']);
        $erased = $finisher->finish($meter, static fn (): ?bool => $meterExists($meter));
        $answered[$meter] = $erased !== null;
    }

    return $answered[$meter];
};

/**
 * The viewer-to-meter map (§5.3), which is the integrator's one obligation
 * and here is a cookie and a row. Null means this browser holds no session,
 * which is the ordinary state of every public page on this site.
 *
 * Also null when the meter's close has just been found to have landed: the
 * erasure runs here, before any route sees the session, so no route can act
 * for a reader who has asked to be forgotten.
 */
$binding = static function (Request $request) use ($store, $finishClose): ?Binding {
    $id = Session::idFrom($request);
    $found = $id === null ? null : $store()->bindingForSession($id);

    if ($found !== null && $finishClose($found->meter)) {
        return null;
    }

    return $found;
};

/**
 * Everything this request knows about the chain, read once.
 *
 * SPEC §6.2's diagram reads the site's three accounts, the meter and the
 * fund's token account in one round trip. Until 2026-09-10 the delegate
 * design's equivalent took two, because the site read and the reader's read
 * were separate closures that happened to run in that order. A HAR that
 * morning put the median round trip at 1242 ms, so the second one was not a
 * rounding error.
 *
 * The session is settled before the object is built, from the cookie and the
 * store, which costs no round trip. That is what lets one call cover five
 * accounts instead of three: the request knows which meter it holds before it
 * knows anything about the chain.
 *
 * **The read ends the session when the chain says to** (§5.3). A meter that is
 * gone, or that names another browser's key, ends every session under the
 * old key at the read that finds it. `RequestRead` asks on every read, and
 * this is where its answer reaches the store.
 *
 * Three by-reference variables and three closures used to live here. One of
 * them, `$stateAlreadyRead`, was written `function` rather than `fn` because an
 * arrow function would have captured `false` for the life of the request —
 * `FrontControllerTest` guards that shape, and it is no accident that the
 * front controller is where it kept happening.
 *
 * **The `static` is a per-request cache only because this file is executed
 * again for every request**, which is true of `php -S` and of PHP-FPM and is
 * not true of a worker SAPI that boots once. This object holds a *reader's*
 * accounts, so there the staleness would be one reader shown another's
 * meter. SPEC §12.1's second caveat says it properly.
 */
$reads = static function (Request $request) use ($config, $rpcFactory, $binding, $store): RequestRead {
    static $reads = null;

    return $reads ??= new RequestRead(
        $config,
        $rpcFactory(),
        $binding($request),
        static function (Binding $ended) use ($store): void {
            $store()->endSessions($ended);
        },
    );
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
 * `$result` is checked too: it means a caller has request-scoped data the
 * deferred route could not reconstruct.
 */
$inspector = static function (?RequestRead $reads, ?MeterResult $result = null) use ($panel): ?array {
    // One condition where there were three. A meter read goes through the
    // same object as the site read, so a request holding one has read.
    // `$reads === null` is the shape of a page that never asked for the
    // object at all.
    if ($reads === null || (!$reads->hasRead() && $result === null)) {
        return null;
    }

    return $panel->sections($reads->site(), $reads->error(), $reads->meter(), $result);
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
        'page_price_demo' => Units::fromBaseUnits($state?->site->itemPrice ?? (int) $params['page_price'], $d),
        'min_limit_demo' => Units::fromBaseUnits($state?->site->minLimit ?? (int) $params['min_limit'], $d),
        'threshold_demo' => Units::fromBaseUnits($state?->site->collectionThreshold ?? (int) $params['collection_threshold'], $d),
        'from_chain' => $state !== null,
    ];
};

/**
 * SPEC §7's decision, assembled. Built lazily: a request that is not going
 * to meter should not construct an RPC client and load a keypair to find
 * that out.
 */
$meterFactory = static function () use ($config, $rpcFactory, $store): Meter {
    $rpc = $rpcFactory();

    return new Meter($config, $rpc, Submitter::fromConfig($rpc, $config), $store());
};

/**
 * §7.3's second half (2026-09-17): what became of a charge the article was
 * already served on. Separate from `$meterFactory` on purpose — it holds no
 * keypair and builds no instruction, so the GET may have it, and
 * `SafeMethodTest`'s rule that nothing a GET can reach meters stays true
 * without an exception.
 */
$followUpFactory = static function () use ($config, $rpcFactory, $store): ChargeFollowUp {
    $rpc = $rpcFactory();

    return new ChargeFollowUp($config, $rpc, Submitter::fromConfig($rpc, $config), $store());
};

/**
 * The development stand-in for getting a meter into this browser before setup
 * exists (fund design, slice 2; removed when slice 3's scan lands). The panel
 * shows this browser's public key, `bin/fund-trials hand` renews a trial
 * meter to it, and the page is told the meter's address. The key never
 * leaves the browser, so the affordance moves nothing a reader's setup would
 * not.
 *
 * Gated as SPEC §12.6 gates the development wallet: off unless configured on,
 * and refused unless the request comes from a loopback address and the RPC
 * endpoint is devnet's.
 */
$devKeyTrial = static function (Request $request) use ($config): bool {
    $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

    return (bool) ($config->development()['key_trial'] ?? false)
        && in_array($remote, ['127.0.0.1', '::1'], true)
        && str_contains((string) parse_url($config->rpcUrl(), PHP_URL_HOST), 'devnet');
};

/**
 * One meter account, as the chain holds it now, or null when there is none.
 * For the key proof (SPEC §5.2), which reads the meter a browser names before
 * any session says which meter that is.
 */
$readMeter = static function (string $address) use ($rpcFactory): ?OnChainMeter {
    $account = $rpcFactory()->multipleAccounts([$address])[0];

    return $account === null ? null : OnChainMeter::decode($account['data']);
};

/**
 * Everything the meter panel draws, in the units the reader sees.
 *
 * There is no sign-in screen (SPEC §5.6). sol-pay's state diagram has no
 * sign-in node: `identified` is a choice, not a screen, and "viewer not
 * identified" goes straight to `set_meter`. Identifying happens inside the
 * panel, where the money is about to move, and that is what keeps its
 * narrowness visible.
 *
 * The stages: `anonymous` (no session), `unreadable`, `metered`, and the two
 * the preflight can block on, `limit` and `expired`, then `failed` when the
 * chain refused a charge the preflight let through.
 */
$meterVars = static function (Request $request, ?MeterResult $result = null) use ($reads, $config, $store, $devKeyTrial): array {
    $params = $config->siteParams();
    $read = $reads($request);
    $state = $read->site();
    $decimals = $state?->mintDecimals ?? (int) $params['decimals'];
    $itemPrice = $state?->site->itemPrice ?? (int) $params['page_price'];

    $panel = [
        'stage' => 'anonymous',
        // The read found the meter gone or renewed to another key, and ended
        // the session (§5.3). The panel says so rather than offering a
        // returning reader a setup as though nothing had happened.
        'ended' => $read->ended(),
        'symbol' => (string) $params['symbol'],
        'decimals' => $decimals,
        'balance' => '0',
        'limit_floor' => Units::fromBaseUnits((int) $params['min_limit'], $decimals),
        'items_remaining' => null,
        'blocked' => null,
        'meter' => null,
        'provisioned' => $config->isProvisioned(),
        // What the metering step did, when there was one (§7).
        'result' => $result,
        'page_price' => Units::fromBaseUnits($itemPrice, $decimals),
        'step_views' => (int) $config->metering()['demo_step_views'],
        // Filled in below when there is a meter to ask about.
        'solvency' => null,
        // SPEC §10.4 qualification 3: closing costs the reader the articles
        // they hold, and they are told how many before they click.
        'live_grants' => 0,
        'dev_key_trial' => $devKeyTrial($request),
    ];

    if ($read->binding() === null) {
        return $panel;
    }

    $meter = $read->meter();
    if ($meter === null || $meter->meter === null) {
        // The chain could not be read. An unmetered page owes it nothing, so
        // the article still serves and the panel says what happened (§9's
        // "a failed read does not take the site down" — which stops being
        // true at the metering path, and should).
        $panel['stage'] = 'unreadable';

        return $panel;
    }

    $now = time();
    $onChain = $meter->meter;

    // **What would stop the next settle, asked before it is attempted.**
    //
    // The preflight answers questions about the expiry and the limit. It
    // knows nothing about whether the fund can pay, because the payment
    // happens inside a `transfer_checked` CPI and SPL is the one that refuses
    // (§8.2). Nothing stops the site reading the fund's balance before, and
    // the difference to a reader is between a button that fails and a button
    // that says why it would.
    //
    // The amount asked about is what a settle would move: the residue
    // already carried, plus what the demo control is about to add. And only
    // when the control would settle at all: an advance that stays under the
    // collection threshold moves nothing, so a short fund cannot refuse it.
    $step = (int) $config->metering()['demo_step_views'];
    $wouldMove = $meter->wouldMove($step);
    $short = $meter->shortOfSettling($step);
    $panel['solvency'] = [
        'would_move' => Units::fromBaseUnits($wouldMove, $decimals),
        'short' => $short,
        'short_demo' => Units::fromBaseUnits($short, $decimals),
        'clear' => $short === 0,
    ];

    $panel['balance'] = Units::fromBaseUnits($meter->balance(), $decimals);
    $panel['limit_floor'] = Units::fromBaseUnits($meter->limitFloor(), $decimals);
    $panel['meter'] = [
        'address' => $meter->meterAddress,
        'limit' => Units::fromBaseUnits($onChain->limit, $decimals),
        'used' => Units::fromBaseUnits($onChain->used, $decimals),
        'paid' => Units::fromBaseUnits($onChain->paid, $decimals),
        'unpaid' => Units::fromBaseUnits($onChain->unpaid(), $decimals),
        'expiry' => gmdate('Y-m-d H:i', $onChain->expiry).' UTC',
    ];
    $panel['items_remaining'] = $meter->itemsRemaining();
    $panel['live_grants'] = $store()->liveGrantCount($meter->meterAddress);

    $blocked = $meter->blocked($now);
    $panel['stage'] = match ($blocked?->kind) {
        null => 'metered',
        BlockedKind::Expired => 'expired',
        default => 'limit',
    };
    $panel['blocked'] = $blocked === null ? null : (string) $blocked;

    // §8: every branch that leaves the happy path early is a screen, so
    // what the chain actually said outranks what the preflight predicted.
    if ($result !== null) {
        $panel['stage'] = match ($result->outcome) {
            MeterOutcome::Blocked => $result->blocked?->kind === BlockedKind::Expired ? 'expired' : 'limit',
            MeterOutcome::Failed => 'failed',
            MeterOutcome::Unreadable => 'unreadable',
            default => 'metered',
        };
        if ($result->blocked !== null) {
            $panel['blocked'] = (string) $result->blocked;
        }
    }

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
$articleContent = static function (Request $request, Library $library, Piece $piece, ?MeterResult $result, ?array $advanced = null) use ($view, $siteVars, $meterVars, $reads): string {
    $body = $result !== null && $result->serves() ? $piece->body() : null;

    // What the seven-view control just did. The advance's own answer carries
    // it when the advance rendered this (`POST /meter/advance` with
    // `X-Fragment`); otherwise it comes back from that POST's redirect, in the
    // query, and is shown once.
    $query = $request->getQueryParams();
    $tx = (string) ($query['tx'] ?? '');
    $outcome = MeterOutcome::tryFrom((string) ($query['advance'] ?? ''));
    $advanced ??= $outcome === null ? null : [
        'outcome' => $outcome,
        'views' => max(0, min(999, (int) ($query['views'] ?? 0))),
        // Signature-shaped or nothing: a query string is reader-supplied.
        'signature' => preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,90}$/', $tx) === 1 ? $tx : null,
        'settled' => ($query['settled'] ?? '') === '1',
    ];

    // The library is already open — the route found this piece in it — so the
    // two links under the body cost a lookup rather than a second read of the
    // manifest. They are rendered whether or not the body is there; the
    // template shows them only where there is an afterwards to offer.
    [$previous, $next] = $library->neighbours($piece->slug);

    return $view->render('article', [
        'piece' => $piece,
        'body' => $body,
        'site' => $siteVars($reads($request)),
        'meter' => $meterVars($request, $result) + ['advanced' => $advanced],
        'previous' => $previous,
        'next' => $next,
    ]);
};

/**
 * SPEC §7.1: **a GET never meters** (2026-09-11). Charging is the POST below.
 *
 * This route answers one of three ways, and decides which without asking the
 * chain anything — the session's meter comes from the cookie and the store,
 * and the grant is a row:
 *
 * - **A reader holding a live grant** gets the article, whole. §7.1 says a
 *   request that finds a live grant is served without touching the chain, and
 *   the decision here does not; the one read on this page is the meter strip's
 *   arithmetic, which claim 7 says must come from an account.
 * - **A reader the site could charge** — a session, a metered piece, no grant —
 *   gets the *shell*: the lede, and a form that posts to this same URL. It is
 *   rendered from nothing but the content index, so it arrives in the time it
 *   takes to send it, and `assets/read-on.js` posts the form at once and puts
 *   the answer where the form was. The three to ten seconds of validator that
 *   used to pass with the old page on screen now pass with the new one, saying
 *   what it is waiting for. Without JavaScript the form is a button.
 * - **Anybody else** — no session, an unmetered piece, a copy not set up — gets
 *   the lede and the meter, exactly as before.
 *
 * The shell does not know whether the POST will charge, set a meter, or stop
 * at a limit, because knowing would cost the read the shell exists to avoid.
 * So it claims nothing the chain would have to answer — not even the price,
 * which comes from the `Site` account and arrives with the rest.
 */
$app->get('/a/{slug}', function (Request $request, Response $response, array $args) use ($view, $shell, $page, $contentDir, $articleContent, $reads, $binding, $store, $config, $followUpFactory): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $library = Library::load($contentDir);
    $piece = $library->find((string) $args['slug']);
    if (!$piece instanceof Piece) {
        return $page($response, $shell('Not found', $view->render('not-found')), 404);
    }

    /**
     * An unmetered piece is served whole, here, at the ordinary slug
     * (2026-09-14).
     *
     * Until now the only public page was `/privacy`, on a route of its own
     * that §10.2 pins to that URL, and a piece marked `metered: false`
     * anywhere else was a 404 — front matter that quietly produced nothing.
     *
     * What needed it was the inspector. The panel now carries one line and a
     * link to the piece that explains it, and a link on every page of the site
     * to an article that charges to be read is a documentation page behind a
     * paywall. Reference matter about how to check the site's claims cannot be
     * one of the things the site sells.
     *
     * No `$reads`, so the panel on this page is deferred exactly as it is on
     * `/privacy`: a page that asks nothing of the chain should not spend an
     * account read filling a panel most readers never open (§9, 2026-09-09).
     *
     * `Library::articles()` is deliberately untouched, so an unmetered piece
     * is not on the index and not in the previous/next sequence. The index
     * lists what is for sale; this one is reached from the panel it describes.
     */
    if (!$piece->metered) {
        return $page($response, $shell($piece->title, $view->render('page', [
            'piece' => $piece,
            'body' => $piece->body(),
        ])));
    }

    $held = $binding($request);
    $result = null;

    if ($held !== null && $config->isProvisioned() && Decision::shouldMeter($piece, $held)) {
        $grant = $store()->liveGrant($held->meter, $piece->slug);
        if ($grant === null) {
            // No `$reads`: the inspector is deferred exactly as it is on
            // `/privacy`, and the POST's answer fills it.
            return $page($response, $shell($piece->title, $view->render('article-pending', [
                'piece' => $piece,
                'longWaitMs' => (int) $config->metering()['long_wait_ms'],
            ])));
        }

        // **A grant whose charge is still out** (§7.3, 2026-09-17). Asked
        // once, not waited for, and before anything on this page reads the
        // accounts — so that when the answer is "landed", the strip's read
        // comes after it and includes the charge. This is how a reader
        // without JavaScript ever hears the rest: reload. A grant already
        // settled either way costs nothing here, which is every grant after
        // its first minute or so.
        $result = $grant['charge'] === ChargeState::Pending
            ? ($followUpFactory()->report($held->meter, $piece->slug, false) ?? MeterResult::granted($grant['charge']))
            : MeterResult::granted($grant['charge']);
    }

    return $page($response, $shell($piece->title, $articleContent($request, $library, $piece, $result), $reads($request), $result));
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
$articlePost = $app->post('/a/{slug}', function (Request $request, Response $response, array $args) use ($view, $shell, $page, $contentDir, $articleContent, $inspector, $reads, $followUpFactory): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $library = Library::load($contentDir);
    $piece = $library->find((string) $args['slug']);
    // The GET serves an unmetered piece whole; there is nothing here to
    // charge for, so this stays a refusal rather than gaining a second branch.
    if (!$piece instanceof Piece || !$piece->metered) {
        return $page($response, $shell('Not found', $view->render('not-found')), 404);
    }

    // §7's decision was made by the middleware, before this handler ran, and
    // this is the whole of what the handler does with it. Anything else here —
    // a second read, a "just in case" charge — would be metering per request,
    // which is §7.1's defect.
    $metering = $request->getAttribute(MeterMiddleware::ATTRIBUTE);
    $result = $metering instanceof MeterResult ? $metering : null;

    // **A repeated POST whose grant is still waiting on its charge**
    // (2026-09-17). Without JavaScript, reloading the page this POST answered
    // resubmits it, and nothing else would ever ask the chain — so this asks
    // once, as the GET does. Found in the capture with JavaScript off: the
    // resubmitted form said "the chain was not touched" over a grant whose
    // charge had not been checked.
    $held = $reads($request)->binding();
    if ($result !== null && $result->outcome === MeterOutcome::Granted && $result->awaiting() && $held !== null) {
        $result = $followUpFactory()->report($held->meter, $piece->slug, false) ?? $result;
        if (!$result->awaiting()) {
            // The middleware read the accounts before this answer existed.
            $reads($request)->invalidateMeter();
        }
    }

    $content = $articleContent($request, $library, $piece, $result);

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
 * SPEC §7.3's second half (2026-09-17): the page asks what became of the
 * charge it was served on.
 *
 * The charging POST answers as soon as the endpoint accepts the transaction,
 * with a strip that says the charge is on its way and shows no account
 * figures — any read taken then would predate the charge. `assets/charge.js`
 * sends this straight after, and this is where the confirmation window now
 * lives: off the request the reader was waiting on. It answers in the same
 * shape as the POST, the article and the inspector rendered from one request,
 * and `swap.js` replaces both.
 *
 * The signature comes from the grant, never from the request. A value the
 * browser sent would be the reader's word for which transaction to ask about.
 *
 * Only as a fragment. Without JavaScript nothing sends this, and the reader's
 * reload — `GET /a/{slug}`, which asks once — does the same job.
 */
$app->post('/a/{slug}/confirm', function (Request $request, Response $response, array $args) use ($view, $contentDir, $articleContent, $inspector, $binding, $reads, $followUpFactory): Response {
    $slug = (string) $args['slug'];
    if ($request->getHeaderLine('X-Fragment') !== '1') {
        return $response->withStatus(303)->withHeader('Location', '/a/'.rawurlencode($slug));
    }

    $library = Library::isBuilt($contentDir) ? Library::load($contentDir) : null;
    $piece = $library?->find($slug);
    if (!$library instanceof Library || !$piece instanceof Piece || !$piece->metered) {
        return $response->withStatus(404);
    }

    $held = $binding($request);
    $result = $held === null ? null : $followUpFactory()->report($held->meter, $piece->slug, true);
    if ($result === null) {
        // No reader, or no grant for this article: nothing was bought, so
        // there is nothing to confirm. The page keeps what it has.
        return $response->withStatus(409);
    }

    // The chain has answered, or the window has closed. Either way nothing on
    // this request has read the accounts yet, so the strip's read below is
    // the first and comes after the answer.
    $reads($request)->invalidateMeter();

    $response->getBody()->write(
        $articleContent($request, $library, $piece, $result)
        .$view->render('inspector', ['sections' => $inspector($reads($request), $result)])
    );

    return $response
        ->withHeader('Content-Type', 'text/html; charset=utf-8')
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
$app->post('/meter/advance', function (Request $request, Response $response) use ($view, $contentDir, $articleContent, $inspector, $reads, $store, $config, $meterFactory): Response {
    // A form and a redirect, and — since 2026-09-11 — a fragment when the
    // page asks for one, which is the same bargain the article makes: the
    // browser gets the answer without a second request, and a browser without
    // JavaScript gets the redirect and loses nothing but the round trip.
    $body = (array) $request->getParsedBody();
    $slug = (string) ($body['slug'] ?? '');
    $back = $slug === '' ? '/' : '/a/'.rawurlencode($slug);

    $held = $reads($request)->binding();
    $state = $reads($request)->site();
    $result = null;

    // The meter is read before the advance as well as inside it: the read is
    // what asks whether this session still holds the meter (§5.3), and a
    // session it ends has nothing to advance.
    if ($held !== null && $state !== null && $reads($request)->meter() !== null) {
        $result = $meterFactory()->advance($held, $state, (int) $config->metering()['demo_step_views']);

        // The *state* is not passed back through the URL — the article page
        // re-reads the meter anyway, so a refusal arrives as §8.2's screen
        // rather than as a message about a screen. The **signature** is,
        // because it is the one thing the page cannot re-derive and the site
        // deliberately does not keep.
        //
        // Not stored, and that is the point: §10.4 enumerates this site's
        // stores and says a table added here is a claim on the privacy page.
        // A per-meter log of metering transactions is precisely the reading
        // history the rest of this design exists to avoid holding, so the
        // signature is handed to the reader once and forgotten. It is public
        // on chain either way; what would be new is *this site* keeping it.
        // **The outcome comes back too, and it has to.** The original version
        // carried only a signature, on the reasoning that the article page
        // re-reads the meter and a refusal would show up in the fresh
        // preflight. That is true of `LimitReached` and false of everything
        // else: a settle that fails leaves the meter exactly as it was, so
        // the re-read says all is well and the click appears to have done
        // nothing at all. `can_meter` is a *limit* check, not a solvency one —
        // §8.2 is explicit that a short balance surfaces from inside the
        // transfer CPI and nowhere earlier.
        $back .= '?advance='.rawurlencode($result->outcome->value)
            .'&views='.$result->items;
        if ($result->signature !== null) {
            $back .= '&tx='.rawurlencode($result->signature)
                .($result->settles ? '&settled=1' : '');
        }

        // §7.2's lock has read these accounts more recently than this request
        // did, and a transaction has moved them. Same two answers as the
        // article's middleware, for the same reason (§2's claim 7).
        if ($result->state !== null) {
            $reads($request)->adopt($result->state);
        } elseif ($result->sent()) {
            $reads($request)->invalidateMeter();
        }
    }

    $library = Library::isBuilt($contentDir) ? Library::load($contentDir) : null;
    $piece = $library?->find($slug);

    if ($request->getHeaderLine('X-Fragment') === '1' && $library instanceof Library && $piece instanceof Piece) {
        // **This is what pays off §9's last debt.** The redirect carries the
        // signature and cannot carry the instructions — they are built in this
        // request and §10.4 leaves nowhere to keep them — so the panel that
        // followed an advance showed no last-transaction section at all. Here
        // the panel is rendered by the request that built them, from the
        // `MeterResult` itself, which is the only place §9's section can come
        // from. Nothing is stored and nothing is rebuilt for display.
        //
        // The *page's* result is not this one. An advance is not a page view
        // (§7.4): the reader is holding a grant, bought when the page that
        // carries this button was served, and that is what the strip reports
        // about the article itself. So the article renders from the grant and
        // the advance renders as the advance — including when it was refused,
        // where the reader keeps the body they already paid for.
        $held = $reads($request)->binding();
        $granted = $held !== null && $store()->liveGrant($held->meter, $piece->slug) !== null;
        $advanced = $result === null ? null : [
            'outcome' => $result->outcome,
            'views' => $result->items,
            'signature' => $result->signature,
            'settled' => $result->settles,
        ];

        $response->getBody()->write(
            $articleContent($request, $library, $piece, $granted ? MeterResult::granted() : null, $advanced)
            .$view->render('inspector', ['sections' => $inspector($reads($request), $result)])
        );

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    return $response->withStatus(303)->withHeader('Location', $back);
});

/**
 * `manage_meter` (§6). Reachable at any time, not only at the limit — decided
 * 2026-09-02, reversing an earlier decision, because a reader who has let a
 * site draw on their fund may reasonably expect to find, at any moment and
 * without exhausting anything first, a page that says what they have spent and
 * offers a way out. Making them hit a limit to reach the exit is not a
 * defensible product, whatever the state diagram omits.
 *
 * The figures come from the same `$meterVars` the article's panel draws, so
 * the two screens cannot describe one meter two ways.
 */
$app->get('/meter', function (Request $request, Response $response) use ($view, $shell, $page, $reads, $siteVars, $meterVars): Response {
    $vars = $meterVars($request);

    return $page($response, $shell('The meter', $view->render('manage-meter', [
        ...$vars,
        'stage' => match ($vars['stage']) {
            'anonymous', 'unreadable' => $vars['stage'],
            default => 'open',
        },
        'site' => $siteVars($reads($request)),
    ]), $reads($request)));
});

/**
 * SPEC §5.2, step 1: a nonce for a key proof, 32 random bytes, stored with the
 * time it was issued. Nothing about the browser is recorded with it.
 */
$app->post('/key/nonce', function (Request $request, Response $response) use ($json, $store, $config): Response {
    return $json($response, ['nonce' => $store()->issueNonce((int) $config->auth()['nonce_ttl_s'])])
        ->withHeader('Cache-Control', 'no-store');
});

/**
 * SPEC §5.2, step 3, and §5.3: a browser that holds a key and a meter address
 * proves the key, and the server binds a session to that meter. No wallet.
 *
 * The page sends the meter's address, the nonce and the signature. It does
 * not send its key: the meter names the key, and the signature is checked
 * against that. See {@see KeyProof} for the order of the checks.
 */
$app->post('/key/prove', function (Request $request, Response $response) use ($json, $store, $config, $readMeter, $finishClose): Response {
    if (!$config->isProvisioned()) {
        return $json($response, ['message' => 'this site is not provisioned'], 409);
    }

    $body = json_decode((string) $request->getBody(), true);
    $meter = is_array($body) ? (string) ($body['meter'] ?? '') : '';
    $nonce = is_array($body) ? (string) ($body['nonce'] ?? '') : '';
    $signature = base64_decode(is_array($body) ? (string) ($body['signature'] ?? '') : '', true);
    if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $meter) !== 1 || $signature === false) {
        return $json($response, ['message' => 'that is not a meter address and a signature'], 400);
    }

    // The origin the browser is looking at, which is what the page signed.
    $uri = $request->getUri();
    $origin = $uri->getScheme().'://'.$uri->getAuthority();

    try {
        $proven = (new KeyProof($store(), $config->provisioned()['site'], $readMeter))
            ->check($meter, $nonce, $origin, $signature);
    } catch (RpcException|DecodeException $e) {
        return $json($response, ['message' => 'the meter could not be read: '.$e->getMessage()], 502);
    }

    if (!$proven instanceof Binding) {
        return $json($response, ['message' => $proven->sentence(), 'reason' => $proven->value], 403);
    }

    // A close of this meter may have landed since the server last looked.
    // Finish that erasure first, so the new session starts after it rather
    // than being swept away by it on the next request. A meter the proof just
    // read is there, so in practice this finds nothing to erase; it is here
    // for the order, which `LateCloseWiringTest` holds.
    $finishClose($proven->meter);

    $old = Session::idFrom($request);
    if ($old !== null) {
        $store()->destroySession($old);
    }
    $id = $store()->createSession($proven, (int) $config->auth()['session_ttl_s']);

    return Session::issue($json($response, ['ok' => true, 'meter' => $proven->meter]), $id, Session::isSecure($request));
});

/**
 * SPEC §5.4, step 1: compile `close_meter` for this session's key to sign,
 * keep it against the session, and hand the page its bytes.
 *
 * The rent returns to the wallet the fund names, so the fund is read for its
 * `reader`. The blockhash is fetched here, immediately before the page signs.
 */
$app->post('/meter/close/prepare', function (Request $request, Response $response) use ($json, $reads, $store, $config, $rpcFactory): Response {
    $id = Session::idFrom($request);
    $meter = $reads($request)->meter();
    $held = $reads($request)->binding();
    if ($id === null || $held === null || $meter === null || $meter->meter === null) {
        return $json($response, ['message' => 'this browser holds no meter here'], 409);
    }

    try {
        $rpc = $rpcFactory();
        $fund = $rpc->multipleAccounts([$held->fund])[0];
        if ($fund === null) {
            return $json($response, ['message' => 'the fund this meter draws on is not there'], 409);
        }
        $message = MeterClose::compose(
            $config->program(),
            $config->provisioned()['site'],
            $held->fund,
            Fund::decode($fund['data'])->reader,
            $held->key,
            Keypair::load($config->keypairPath('authority'))->address,
            $rpc->latestBlockhash()['blockhash'],
        );
    } catch (RpcException|DecodeException $e) {
        return $json($response, ['message' => 'the chain could not be read: '.$e->getMessage()], 502);
    }

    $store()->keepCloseMessage($id, $message);

    return $json($response, ['message' => base64_encode($message)])->withHeader('Cache-Control', 'no-store');
});

/**
 * SPEC §5.4, steps 3 and 4: the page returns the kept message's signature.
 * The server checks it against the session's key, adds the authority's
 * signature, sends, and erases its record of the meter (§10.4).
 *
 * The order is the reverse of the metering path's. Here the site waits for the
 * chain **before** deleting anything, because a purge on the strength of an
 * unconfirmed close would erase a reader whose meter is still open and still
 * spending. The evidence is the account: the meter is gone. When it is still
 * there, nothing is deleted and a note is left, so that a later request can
 * finish the erasure once the close lands (`$finishClose`).
 */
$app->post('/meter/close', function (Request $request, Response $response) use ($json, $reads, $store, $config, $rpcFactory, $meterExists): Response {
    $id = Session::idFrom($request);
    $held = $reads($request)->binding();
    $message = $id === null ? null : $store()->closeMessage($id);
    if ($id === null || $held === null || $message === null) {
        return $json($response, ['message' => 'there is no close waiting to be signed; start again'], 409);
    }

    $body = json_decode((string) $request->getBody(), true);
    $signature = base64_decode(is_array($body) ? (string) ($body['signature'] ?? '') : '', true);
    $authority = Keypair::load($config->keypairPath('authority'));
    $wire = $signature === false ? null : MeterClose::assemble($message, $held->key, $signature, $authority);
    if ($wire === null) {
        return $json($response, ['message' => 'that is not this browser key\'s signature of the close'], 400);
    }

    $outcome = Submitter::fromConfig($rpcFactory(), $config)->sendWire($wire);
    if ($outcome->status === SubmitStatus::Failed) {
        // §8.2: `Unauthorized` is the key on this device not being the
        // meter's, because another device renewed it. The session ends.
        if ($outcome->cause?->payError === PayError::Unauthorized) {
            $store()->endSessions($held);
        }

        return $json($response, ['message' => 'the close was refused: '.$outcome->detail], 409);
    }

    // The account is the evidence, not the signature.
    if ($meterExists($held->meter) !== false) {
        $store()->recordPendingClose($held->meter, (string) $outcome->signature, (int) $config->auth()['session_ttl_s']);

        return $json($response, [
            'ok' => false,
            'pending' => true,
            'signature' => $outcome->signature,
            'message' => 'sent, and the meter is still on chain; this site will forget it once the close lands',
        ], 202);
    }

    // §10.4. Sessions, grants, the lock row and any note go; the faucet
    // ledger survives for the published reason.
    $erased = $store()->eraseMeter($held->meter);

    return Session::clear($json($response, [
        'ok' => true,
        'signature' => $outcome->signature,
        'erased' => $erased,
    ]), Session::isSecure($request));
});

/**
 * §10.2: the site carries a page at the URL a privacy policy would occupy.
 *
 * It carries it by sending the reader to where every other piece is served
 * (2026-09-14). Until `GET /a/{slug}` learned to serve an unmetered piece this
 * route was the only way a public page could exist, so it did the whole job
 * itself: its own `isBuilt` check, its own lookup, its own `page.php` render.
 * That made **two implementations of one thing**, and they had already drifted
 * — this one refused a piece that *was* metered, the article route refused one
 * that was not, and neither knew about the other's branch.
 *
 * §10.2 asks that the URL carry the page, not that a second handler render it.
 * A permanent redirect satisfies the promise and leaves one renderer.
 *
 * **The site's own links still point here**, from the footer of every page
 * (§10.2 again), from the index, from the meter and from `manage_meter`. The
 * promised URL is the one worth writing in the markup; a browser caches the
 * 301 after the first hit. Pointing them at `/a/privacy` instead would make
 * this URL a thing only strangers ever reach, which is the opposite of what
 * §10.2 is for.
 */
$app->get('/privacy', function (Request $request, Response $response): Response {
    return $response->withHeader('Location', '/a/privacy')->withStatus(301);
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

    return new Provisioner($config, $rpc, Submitter::fromConfig($rpc, $config));
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
    // The site's answer to a forged POST is `SameSite=Lax` on the session
    // cookie (§5), and this is the one route with no session for it to be
    // absent from. Refused here, before the provisioner is built, so a request
    // from somebody else's page does not even open an RPC connection.
    if (!SameOrigin::allows($request)) {
        return $page($response, $shell('Setup stopped', $view->render('setup-ran', [
            'steps' => [Step::blocked(
                'request',
                'This request did not come from a page on this site, so setup did not run. Open the setup screen and press the button there.',
            )],
            'provisioned' => Config::load($root)->isProvisioned(),
            'refused' => true,
        ])), 403);
    }

    $steps = $provisioner()->run();

    // Re-read from disk: the provisioner wrote var/site.json as it went, and
    // the config this request started with predates that.
    $provisioned = Config::load($root)->isProvisioned();

    return $page($response, $shell($provisioned ? 'Provisioned' : 'Setup stopped', $view->render('setup-ran', [
        'steps' => $steps,
        'provisioned' => $provisioned,
        'refused' => false,
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
            // The count is a count and the expiry a time. Everything else is a
            // token amount and gets both unit forms, for the reason §9 gives
            // about the panel generally: a six-decimal scaling error is
            // invisible in one form.
            //
            // The short name and nothing else, since 2026-09-12: the panel's
            // first table defines it, beside the base58 and the copy button,
            // and this line repeating all 44 characters was the last place a
            // reader had to read an address instead of recognising one.
            // `Alias::for` is a hash of the address, so this needs no state.
            //
            // It is the one short name on the page that is not a link to its
            // row. The sentence arrives from this endpoint as text and the
            // panel writes it with `textContent`; making one word of it a link
            // would mean composing markup here and trusting it there, for a
            // row that already sits a few lines under the table.
            $field === 'meter' => Alias::for(Alias::METER, (string) $value),
            $field === 'items' => (string) $value,
            // `Renewed` carries the meter's new expiry, in Unix seconds. A date
            // reads where a ten-digit number does not.
            $field === 'expiry' => gmdate('Y-m-d H:i', (int) $value).' UTC',
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
$app->get('/health', function (Request $request, Response $response) use ($config, $contentDir, $store): Response {
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

    // §10.4 q.1: how long the oldest expired grant or session has waited for
    // a sweep. `bin/sweep` on its schedule keeps the wait under `every_s`, so
    // `overdue` means the schedule is not running. Read only when the database
    // exists, so that asking does not create one.
    if (is_file($config->dbPath())) {
        $every = (int) $config->metering()['sweep_every_s'];
        $waited = $store()->oldestExpired();
        $payload['sweep'] = [
            'every_s' => $every,
            'oldest_expired_s' => $waited,
            'overdue' => $waited !== null && $waited > $every,
        ];
    }

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

/**
 * **Run it, unless somebody asked for the app itself** (2026-09-23).
 *
 * A real SAPI — `php -S`, fpm, anything pointed here — is present to serve a
 * request, so this runs. Under the CLI it is a test that has `require`d this
 * file, and what a test wants is `$app->handle()` against *this* routing table:
 * the routes, the middleware and the wiring as they actually are, rather than a
 * second assembly of them in a fixture that can drift from this one.
 *
 * Two lines rather than a `bootstrap/app.php`, because seven tests and several
 * SPEC sections name `public/index.php` by path, and the point of the change is
 * to make this file testable rather than to move it.
 */
if (PHP_SAPI !== 'cli') {
    $app->run();
}

return $app;
