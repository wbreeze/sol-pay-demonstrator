<?php

declare(strict_types=1);

namespace Newsprint\Tests\Browser;

use PHPUnit\Framework\TestCase;

/**
 * **A prerendered article does not charge until a reader arrives.**
 *
 * Chrome's prerendering runs a page's scripts before anyone has chosen the
 * page. `assets/read-on.js` posts the form that charges for an article, so the
 * script waits while `document.prerendering` is true. This file checks that
 * wait in a real browser, with a real prerender, against the real template and
 * the real script.
 *
 * **No DevTools.** A browser driven over the DevTools protocol does not
 * prerender at all: Chrome reports `PrerenderingDisabledByDevTools` and loads
 * the page normally on the click. A Playwright or Puppeteer version of this
 * test therefore passes with the check deleted. Found 2026-09-16, by deleting
 * the check. So the browser here is started as a plain process with a URL, and
 * the evidence is the server's own log, not anything the browser reports.
 *
 * **Two runs, and the second one is the reason the first means anything.**
 * The first serves `read-on.js` as it stands and must not post until the
 * reader arrives. The second serves the same script with the check removed and
 * must post before the reader arrives. Both runs also assert that the browser
 * actually prerendered, because a browser that skipped the prerender would
 * post nothing early under either script.
 *
 * The site's half is `prerender-router.php`. It records one line per request,
 * `METHOD path Sec-Purpose`, and writes `GET /mark` at the moment the reader
 * arrives.
 *
 * **Chrome is found or the test is skipped**, except where
 * `NEWSPRINT_REQUIRE_BROWSER=1` is set. CI sets it, because a skipped browser
 * test on CI is a green result that checked nothing. `CHROME_BIN` names a
 * binary when the usual places do not have one.
 */
final class PrerenderTest extends TestCase
{
    private const PROBE = '/a/prerender-probe';

    /** Chrome's first start on a fresh profile is the slow part. */
    private const DEADLINE_S = 30.0;

    /** How long an arrival has to post. Long enough to be patient, short enough that a control run does not wait out a deadline. */
    private const AFTER_ARRIVAL_S = 3.0;

    /** @var list<string> directories to remove afterwards */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            self::remove($dir);
        }
    }

    public function testAPrerenderedArticlePostsOnlyOnceTheReaderArrives(): void
    {
        [$before, $after, $log] = $this->visit(control: false);

        self::assertSame([], $before, "nothing may post before the reader arrives:\n".$log);
        self::assertCount(1, $after, "the reader's arrival posts once:\n".$log);
    }

    /**
     * The control. The identical page, with the one check removed.
     *
     * If this ever stops posting early, the browser has stopped running
     * prerendered scripts, and the test above is reporting a fact about the
     * browser instead of a fact about `read-on.js`.
     */
    public function testWithoutTheCheckThePrerenderedArticlePostsBeforeTheReaderArrives(): void
    {
        [$before, , $log] = $this->visit(control: true);

        self::assertNotSame([], $before, "the control must post during the prerender, or the test above proves nothing."
            ." A line starting with ! in the log below says why the control could not be built:\n".$log);
    }

    /**
     * Serve the probe, let Chrome prerender the article and arrive at it, and
     * split the article's POSTs at the moment of arrival.
     *
     * @return array{0: list<string>, 1: list<string>, 2: string} posts before, posts after, the whole log
     */
    private function visit(bool $control): array
    {
        $chrome = self::chrome();
        $root = dirname(__DIR__, 2);
        $dir = $this->scratchDir();
        $log = $dir.'/requests.log';
        touch($log);

        $port = self::freePort();
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root.'/public', __DIR__.'/prerender-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir.'/server.out', 'w'], 2 => ['file', $dir.'/server.out', 'a']],
            $pipes,
            $root,
            ['NEWSPRINT_PRERENDER_LOG' => $log, 'NEWSPRINT_PRERENDER_CONTROL' => $control ? '1' : '0'] + getenv(),
        );
        self::assertIsResource($server, 'could not start php -S');

        $browser = null;
        try {
            self::waitForPort($port);

            $args = [
                $chrome,
                '--headless=new',
                '--user-data-dir='.$dir.'/profile',
                '--no-first-run',
                '--no-default-browser-check',
                '--password-store=basic',
                '--use-mock-keychain',
            ];
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                // Chrome refuses to run as root with its sandbox on. CI does
                // not run as root; a container usually does.
                $args[] = '--no-sandbox';
            }
            $args[] = "http://127.0.0.1:{$port}/";

            $browser = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            self::assertIsResource($browser, 'could not start '.$chrome);

            $lines = self::waitForArrival($log);
        } finally {
            if (is_resource($browser)) {
                self::stop($browser);
            }
            self::stop($server);
        }

        $text = implode("\n", $lines);
        self::assertContains('GET /mark -', $lines, "the reader never arrived:\n".$text);

        $mark = (int) array_search('GET /mark -', $lines, true);
        $posts = static fn (array $slice): array => array_values(array_filter(
            $slice,
            static fn (string $line): bool => str_starts_with($line, 'POST '.self::PROBE.' '),
        ));
        $before = $posts(array_slice($lines, 0, $mark));
        $after = $posts(array_slice($lines, $mark + 1));

        // The browser prerendered the article, and the arrival used that copy.
        // Without both, neither assertion about the POSTs means anything.
        $gets = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_starts_with($line, 'GET '.self::PROBE.' '),
        ));
        self::assertSame(['GET '.self::PROBE.' prefetch;prerender'], $gets, "one prerendered fetch of the article, and no ordinary one:\n".$text);

        return [$before, $after, $text];
    }

    /**
     * Read the log until the reader has arrived and the arrival has had time
     * to post.
     *
     * Two waits, because they wait for different things. Chrome's first start
     * on a fresh profile can take seconds, so reaching the mark gets a long
     * deadline. After the mark, a POST arrives within a second on any machine
     * that got that far, and a control run never sends one — its form was
     * already swapped away during the prerender. So the second wait is short,
     * and it ends early on the first POST.
     *
     * @return list<string>
     */
    private static function waitForArrival(string $log): array
    {
        $read = static fn (): array => file($log, FILE_IGNORE_NEW_LINES) ?: [];

        $deadline = microtime(true) + self::DEADLINE_S;
        while (!in_array('GET /mark -', $read(), true) && microtime(true) < $deadline) {
            usleep(100_000);
        }

        $deadline = microtime(true) + self::AFTER_ARRIVAL_S;
        while (microtime(true) < $deadline) {
            $lines = $read();
            $mark = array_search('GET /mark -', $lines, true);
            foreach ($mark === false ? [] : array_slice($lines, $mark + 1) as $line) {
                if (str_starts_with($line, 'POST '.self::PROBE.' ')) {
                    // A moment more, so that a second POST would show.
                    usleep(500_000);

                    return $read();
                }
            }
            usleep(100_000);
        }

        return $read();
    }

    private static function chrome(): string
    {
        $named = getenv('CHROME_BIN');
        $candidates = is_string($named) && $named !== '' ? [$named] : [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            'google-chrome',
            'google-chrome-stable',
            'chromium',
            'chromium-browser',
        ];

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/')) {
                if (is_executable($candidate)) {
                    return $candidate;
                }
                continue;
            }
            $found = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));
            if ($found !== '') {
                return $found;
            }
        }

        $message = 'no Chrome or Chromium found; set CHROME_BIN';
        if (getenv('NEWSPRINT_REQUIRE_BROWSER') === '1') {
            self::fail($message);
        }
        self::markTestSkipped($message);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, "no free port: {$error}");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }
        self::fail("php -S did not start on port {$port}");
    }

    /** @param resource $process */
    private static function stop($process): void
    {
        proc_terminate($process);
        $deadline = microtime(true) + 5.0;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir().'/newsprint-prerender-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $this->scratch[] = $dir;

        return $dir;
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() && !$entry->isLink() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
