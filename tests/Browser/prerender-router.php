<?php

declare(strict_types=1);

/**
 * The site's half of `PrerenderTest`. Not a test — PHPUnit loads `*Test.php`,
 * and this file is the router script `php -S` runs.
 *
 *   NEWSPRINT_PRERENDER_LOG=<file> [NEWSPRINT_PRERENDER_CONTROL=1] \
 *       php -S 127.0.0.1:<port> -t public tests/Browser/prerender-router.php
 *
 * It serves four things and writes one line per request to the log:
 *
 * - `/` — a page that asks the browser to prerender the article, waits until
 *   the prerendered copy has loaded its scripts, marks the moment in the log,
 *   and then follows the link. Following the link is the reader arriving.
 * - `GET /a/prerender-probe` — the real `article-pending.php`, rendered by
 *   the real `View`. The page carries the real `/assets/read-on.js`.
 * - `POST /a/prerender-probe` — the charge. It charges nothing. It answers
 *   with an article, so the script's swap succeeds, and the log line is the
 *   whole of the evidence.
 * - `/assets/read-on.js` — served from `public/`, unchanged. Under
 *   `NEWSPRINT_PRERENDER_CONTROL=1` the prerendering check is removed first.
 *   That version is the control: it must charge before anyone arrives.
 *
 * Everything else under `/assets/` is served from `public/` as it stands.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Newsprint\Content\Piece;
use Newsprint\Support\View;

const PROBE = '/a/prerender-probe';

$root = dirname(__DIR__, 2);
$log = (string) getenv('NEWSPRINT_PRERENDER_LOG');
$control = getenv('NEWSPRINT_PRERENDER_CONTROL') === '1';

$method = (string) $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$purpose = (string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? '-');

// `/ready` is asked for repeatedly by the index page, so it is not logged.
if ($path !== '/ready') {
    file_put_contents($log, "{$method} {$path} {$purpose}\n", FILE_APPEND | LOCK_EX);
}

/**
 * Whether the prerendered copy of the article has fetched `swap.js`. That
 * import is the last thing `read-on.js` needs before it can post, so from
 * this point a script without the check would already be charging.
 */
function prerendered(string $log): bool
{
    foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with($line, 'GET /assets/swap.js ') && str_contains($line, 'prerender')) {
            return true;
        }
    }

    return false;
}

if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    $href = PROBE;
    $probe = json_encode(PROBE);
    echo <<<HTML
        <!doctype html>
        <title>prerender probe</title>
        <a id="arrive" href="{$href}">the article</a>
        <script type="speculationrules">{"prerender": [{"source": "list", "urls": [{$probe}]}]}</script>
        <script>
        // Wait for the prerendered copy to be ready. Then give its script time
        // to post, mark the moment, and arrive.
        (async () => {
            const deadline = Date.now() + 15000;
            while (Date.now() < deadline && (await (await fetch('/ready')).text()) !== 'yes') {
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
            await new Promise((resolve) => setTimeout(resolve, 1500));
            await fetch('/mark');
            document.getElementById('arrive').click();
        })();
        </script>
        HTML;

    return true;
}

if ($path === '/ready') {
    echo prerendered($log) ? 'yes' : 'no';

    return true;
}

if ($path === '/mark') {
    echo 'marked';

    return true;
}

if ($path === PROBE && $method === 'GET') {
    $piece = new Piece(
        slug: 'prerender-probe',
        title: 'Prerender probe',
        lede: 'The lede.',
        readingTime: 1,
        metered: true,
        status: 'published',
        created: '2026-09-16',
        revised: null,
        bodyPath: '/nonexistent',
    );
    $article = (new View($root.'/templates'))->render('article-pending', ['piece' => $piece, 'longWaitMs' => 7000]);

    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html>\n<title>Prerender probe</title>\n<main>{$article}</main>\n";

    return true;
}

if ($path === PROBE && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<article class="piece"><p>metered</p></article>';

    return true;
}

if ($path === '/assets/read-on.js') {
    $script = (string) file_get_contents($root.'/public/assets/read-on.js');

    if ($control) {
        $check = 'if (document.prerendering) {';
        if (substr_count($script, $check) !== 1) {
            // The control cannot be built, so the test must not pass on it.
            file_put_contents($log, "! read-on.js no longer has `{$check}` to remove\n", FILE_APPEND | LOCK_EX);
            http_response_code(500);

            return true;
        }
        $script = str_replace($check, 'if (false) {', $script);
    }

    header('Content-Type: text/javascript; charset=utf-8');
    echo $script;

    return true;
}

if (str_starts_with($path, '/assets/')) {
    return false;
}

http_response_code(404);

return true;
