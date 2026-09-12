<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * **No GET can charge anybody** (SPEC §7.1, 2026-09-11).
 *
 * Metering moves a reader's money, so the request that does it is a POST, and
 * a GET is what a browser makes on its own account — prefetching, prerendering,
 * restoring, previewing a link. The article's GET used to meter, defended by a
 * list of prefetch headers that covered only the requests that announced
 * themselves. It no longer meters, and this is what keeps it that way.
 *
 * Only two things in `public/index.php` can meter: `$meterFactory`, which
 * builds the `Meter`, and `MeterMiddleware`, which calls it. So the check is
 * that every route capturing the first, and every route the second is added
 * to, was registered with `$app->post`. Textual, like the capture checks in
 * {@see FrontControllerTest}, because the front controller is one file of
 * closures and the property is about how they are wired.
 */
final class SafeMethodTest extends TestCase
{
    public function testNothingAGetCanReachMeters(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/public/index.php');
        [$found, $wrong] = self::scan($source);

        self::assertGreaterThanOrEqual(2, $found, 'a scanner that found nothing proves nothing');
        self::assertSame([], $wrong);
    }

    /** The same scanner over the shape this site used to have, which it must refuse. */
    public function testTheCheckRefusesAMeteringGet(): void
    {
        $broken = <<<'PHP'
            $article = $app->get('/a/{slug}', function (Request $request) use ($view): Response {
                return $response;
            });
            $article->add(new MeterMiddleware($piece, $reads, $meterFactory));
            $app->get('/sneaky', function (Request $request) use ($wallet, $meterFactory): Response {
                return $response;
            });
            $app->post('/fine', function (Request $request) use ($meterFactory): Response {
                return $response;
            });
            PHP;

        [$found, $wrong] = self::scan($broken);

        self::assertSame(3, $found);
        self::assertSame(["MeterMiddleware on get '/a/{slug}'", "\$meterFactory captured by get '/sneaky'"], $wrong);
    }

    /**
     * @return array{0: int, 1: list<string>} how many metering routes were found, and which were not POSTs
     */
    private static function scan(string $source): array
    {
        $found = 0;
        $wrong = [];

        // `$name = $app->method('path', …` — the routes that are held in a
        // variable, which is how a middleware gets added to one.
        preg_match_all('/\$(\w+)\s*=\s*\$app->(\w+)\(\'([^\']+)\'/', $source, $held, PREG_SET_ORDER);
        $routes = [];
        foreach ($held as [, $name, $method, $path]) {
            $routes[$name] = [$method, $path];
        }

        preg_match_all('/\$(\w+)->add\(new MeterMiddleware\b/', $source, $added);
        foreach ($added[1] as $name) {
            $found += 1;
            [$method, $path] = $routes[$name] ?? ['unknown', $name];
            if ($method !== 'post') {
                $wrong[] = "MeterMiddleware on {$method} '{$path}'";
            }
        }

        preg_match_all('/\$app->(\w+)\(\'([^\']+)\',\s*(?:static\s+)?function\s*\([^)]*\)\s*use\s*\(([^)]*)\)/', $source, $closures, PREG_SET_ORDER);
        foreach ($closures as [, $method, $path, $uses]) {
            if (preg_match('/\$meterFactory\b/', $uses) !== 1) {
                continue;
            }
            $found += 1;
            if ($method !== 'post') {
                $wrong[] = "\$meterFactory captured by {$method} '{$path}'";
            }
        }

        return [$found, $wrong];
    }
}
