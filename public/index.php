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
use Newsprint\Chain\RpcException;
use Newsprint\Chain\Submitter;
use Newsprint\Content\Library;
use Newsprint\Content\Piece;
use Newsprint\Support\Alias;
use Newsprint\Support\Config;
use Newsprint\Setup\Provisioner;
use Newsprint\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use SolPay\Core\Units;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$config = Config::load($root);
$view = new View($root.'/templates');
$contentDir = $root.'/var/content';

$params = $config->siteParams();
$decimals = (int) $params['decimals'];

/** Both unit forms, everywhere, for the reason §9 gives: the six-decimal scaling error is invisible until you see them side by side. */
$site = [
    'symbol' => (string) $params['symbol'],
    'decimals' => $decimals,
    'page_price_demo' => Units::fromBaseUnits((int) $params['page_price'], $decimals),
    'min_limit_demo' => Units::fromBaseUnits((int) $params['min_limit'], $decimals),
    'threshold_demo' => Units::fromBaseUnits((int) $params['collection_threshold'], $decimals),
];

/**
 * What the inspector can show before the site is provisioned: the deployment
 * it is configured against, and the prices it will charge. Everything else in
 * §9 — decoded accounts, preflight, the last transaction — needs a chain read,
 * and arrives with the screens that make one.
 */
$inspector = static function () use ($config, $params, $site, $decimals): array {
    $program = $config->program();

    $sections = [[
        'heading' => 'Deployment',
        'rows' => [
            [Alias::for(Alias::PROGRAM, $program->id), $program->id],
            [Alias::for(Alias::TOKEN_PROGRAM, $program->tokenProgram), $program->tokenProgram],
            ['cluster', 'devnet'],
            ['endpoint', $config->rpcUrl()],
        ],
        'note' => 'The endpoint is called by this server, never by your browser. '
            .'An RPC provider that saw your browser would learn your IP address beside a wallet address; '
            .'seeing this server, it learns that one server asked about some accounts.',
    ], [
        'heading' => 'Site parameters',
        'rows' => [
            ['page price', sprintf('%s %s  (%d base units)', $site['page_price_demo'], $site['symbol'], (int) $params['page_price'])],
            ['collection threshold', sprintf('%s %s  (%d base units — %d views)', $site['threshold_demo'], $site['symbol'], (int) $params['collection_threshold'], intdiv((int) $params['collection_threshold'], (int) $params['page_price']))],
            ['minimum limit', sprintf('%s %s  (%d base units — %d views)', $site['min_limit_demo'], $site['symbol'], (int) $params['min_limit'], intdiv((int) $params['min_limit'], (int) $params['page_price']))],
            ['mint decimals', (string) $decimals],
        ],
    ]];

    if ($config->isProvisioned()) {
        $addresses = $config->provisioned();
        $sections[] = [
            'heading' => 'This site, on chain',
            'rows' => [
                [Alias::for(Alias::SITE, $addresses['site'] ?? ''), (string) ($addresses['site'] ?? '')],
                [Alias::for(Alias::MINT, $addresses['mint'] ?? ''), (string) ($addresses['mint'] ?? '')],
                [Alias::for(Alias::TREASURY, $addresses['treasury'] ?? ''), (string) ($addresses['treasury'] ?? '')],
            ],
        ];
    } else {
        $sections[] = [
            'heading' => 'This site, on chain',
            'rows' => [['status', 'not provisioned']],
            'note' => 'First-run setup has not created the mint, the treasury or the site account yet (§12.0).',
        ];
    }

    return $sections;
};

$app = AppFactory::create();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$page = static function (Response $response, string $html, int $status = 200) use ($view, $inspector, $site): Response {
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

$app->get('/', function (Request $request, Response $response) use ($view, $shell, $page, $contentDir, $site, $config): Response {
    if (!Library::isBuilt($contentDir)) {
        return $page($response, $shell('Nothing built', $view->render('not-built')), 503);
    }

    $articles = Library::load($contentDir)->articles();

    return $page($response, $shell('Newsprint', $view->render('index', [
        'articles' => $articles,
        'site' => $site,
        'provisioned' => $config->isProvisioned(),
    ])));
});

$app->get('/a/{slug}', function (Request $request, Response $response, array $args) use ($view, $shell, $page, $contentDir, $site): Response {
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
        'site' => $site,
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

$app->get('/setup', function (Request $request, Response $response) use ($view, $shell, $page, $provisioner, $config, $site): Response {
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
        'site' => $site,
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
