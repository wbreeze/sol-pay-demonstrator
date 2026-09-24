<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Metering\Meter;
use PHPUnit\Framework\TestCase;
use Newsprint\Store\Database;
use Newsprint\Support\Config;
use Slim\App;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The routing table, asked about itself.
 *
 * {@see SafeMethodTest} asks this question of the file's text, and text is what
 * it can reach: it finds the names it already knows, so a new route into the
 * metering step under another name gets past it, and one of its serve-first
 * cases was vacuous until a mutation run caught it. `public/index.php` returns
 * the app under the CLI for this reason — the table these tests read is the
 * table the site serves, not a second one assembled for testing.
 *
 * The question is §7.1's, and it is the one the whole shape of this site rests
 * on: **a GET cannot charge.** Not "does not today" — cannot, as a property of
 * the table, checked against every route rather than against the handful
 * somebody remembered to name.
 */
final class RouteTest extends TestCase
{
    /**
     * No GET route can reach a `Meter`.
     *
     * Read off the closures rather than the source: a route handler that can
     * meter has to have the factory in scope, and a closure says what it
     * captured. That catches a route added tomorrow under any name, which is
     * exactly what a textual check cannot do.
     */
    public function testNoGetRouteCanBuildAMeter(): void
    {
        $offenders = [];

        foreach ($this->routes() as $route) {
            if (!in_array('GET', $route->getMethods(), true)) {
                continue;
            }

            foreach ($this->captured($route) as $name => $value) {
                if ($value instanceof \Closure && $this->returnsAMeter($value)) {
                    $offenders[] = $route->getPattern().' captures $'.$name;
                }
            }
        }

        self::assertSame([], $offenders, 'a GET that can charge is §7.1 undone');
    }

    /**
     * `/health` reports the age of the oldest expired row, and until now that
     * was checked by booting the app and reading it by hand.
     *
     * The endpoint is the only witness to a promise no test can keep: the
     * privacy page says a receipt is gone within thirty-five minutes, and the
     * sweep that makes that true runs from a crontab nobody here can see. A
     * `sweep` block missing from this payload is that promise going unwatched.
     *
     * The block appears only when the database file is already there, so that
     * asking after a reader's data does not create somewhere to keep it. Both
     * halves are asserted, and the file is put back the way it was found.
     */
    public function testHealthReportsTheSweepOnlyOnceThereIsADatabase(): void
    {
        $db = Config::load(dirname(__DIR__, 2))->dbPath();
        $existed = is_file($db);

        try {
            if ($existed) {
                self::assertArrayHasKey('sweep', $this->health(), 'the database is there, so the sweep is answerable');
            } else {
                self::assertArrayNotHasKey('sweep', $this->health(), 'asking must not create a database');
                Database::open($db);
            }

            $sweep = $this->health()['sweep'] ?? null;
            self::assertIsArray($sweep);
            self::assertArrayHasKey('every_s', $sweep);
            self::assertArrayHasKey('oldest_expired_s', $sweep);
            self::assertArrayHasKey('overdue', $sweep, 'the one field somebody has to read');
        } finally {
            if (!$existed) {
                foreach ([$db, $db.'-wal', $db.'-shm'] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function health(): array
    {
        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://example.test/health'),
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);

        return $payload;
    }

    private function app(): App
    {
        $app = require dirname(__DIR__, 2).'/public/index.php';
        self::assertInstanceOf(App::class, $app, 'public/index.php must hand back the app under the CLI');

        return $app;
    }

    /** @return list<RouteInterface> */
    private function routes(): array
    {
        return array_values($this->app()->getRouteCollector()->getRoutes());
    }

    /** What a route's handler closed over, by name. @return array<string, mixed> */
    private function captured(RouteInterface $route): array
    {
        $callable = $route->getCallable();

        return $callable instanceof \Closure
            ? (new \ReflectionFunction($callable))->getClosureUsedVariables()
            : [];
    }

    /**
     * A factory closure is one that makes a `Meter`. Calling it would open a
     * database and an RPC client, so this reads its return type instead.
     */
    private function returnsAMeter(\Closure $factory): bool
    {
        $type = (new \ReflectionFunction($factory))->getReturnType();

        return $type instanceof \ReflectionNamedType && $type->getName() === Meter::class;
    }
}
