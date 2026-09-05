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

use Newsprint\Chain\Rpc;
use Newsprint\Chain\SiteReader;
use Newsprint\Chain\SiteState;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\Submitter;
use Newsprint\Content\Library;
use Newsprint\Content\Piece;
use Newsprint\Support\Alias;
use Newsprint\Support\Config;
use Newsprint\Setup\Provisioner;
use Newsprint\Support\Inspector;
use Newsprint\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use SolPay\Core\DecodeException;
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
$inspector = static function () use ($panel, $read, &$stateError): array {
    $state = $read();

    return $panel->sections($state, $stateError);
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

$app = AppFactory::create();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$page = static function (Response $response, string $html, int $status = 200) use ($view, $inspector): Response {
    $response->getBody()->write($html);

    return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

$shell = static function (string $title, string $content) use ($view, $inspector): string {
    return $view->render('layout', [
        'title' => $title,
        'content' => $content,
        'inspector' => $inspector(),
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

$app->get('/', function (Request $request, Response $response) use ($view, $shell, $page, $contentDir, $siteVars, $config): Response {
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

$app->get('/a/{slug}', function (Request $request, Response $response, array $args) use ($view, $shell, $page, $contentDir, $siteVars): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $piece = Library::load($contentDir)->find((string) $args['slug']);
    if (!$piece instanceof Piece || !$piece->metered) {
        return $page($response, $shell('Not found', $view->render('not-found')), 404);
    }

    // §7's decision goes here, and there is nothing to decide with yet: no
    // session, no wallet, no contract. Until there is, the body is withheld
    // from everyone, which is the correct behaviour for a reader who has not
    // paid and an honest placeholder for one who has.
    $body = null;

    return $page($response, $shell($piece->title, $view->render('article', [
        'piece' => $piece,
        'body' => $body,
        'site' => $siteVars(),
    ])));
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
