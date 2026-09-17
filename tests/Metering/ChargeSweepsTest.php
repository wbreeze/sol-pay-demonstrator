<?php

declare(strict_types=1);

namespace Newsprint\Tests\Metering;

use Newsprint\Chain\Rpc;
use Newsprint\Chain\SiteState;
use Newsprint\Chain\Submitter;
use Newsprint\Metering\Meter;
use Newsprint\Metering\MeterOutcome;
use Newsprint\Store\Database;
use Newsprint\Store\Store;
use Newsprint\Support\Config;
use PDO;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Site;

/**
 * SPEC §10.4 q.1, and the privacy page's promise that a receipt is gone in
 * thirty minutes.
 *
 * Expiry only hides a row, so the promise rests on a sweep, and the sweep
 * rests on somebody calling it. Found 2026-09-16: `Store::sweepExpired()`
 * existed and had a test, and nothing in the site called it. Every lapsed
 * grant stayed in the table until the reader closed the meter.
 *
 * `StoreTest` shows that the sweep deletes. This test shows that the site
 * runs the sweep. The request below is served from a live grant, so it never
 * reaches the chain; the RPC points at a closed port to make sure of that.
 */
final class ChargeSweepsTest extends TestCase
{
    private int $now = 1_700_000_000;

    public function testAServedRequestDeletesEveryReadersExpiredRows(): void
    {
        $pdo = Database::open(':memory:');
        $store = new Store($pdo, fn (): int => $this->now);

        // Another reader, a week ago: a session, a grant and a lock row.
        $store->createSession('PAYRcat', 3_600);
        $store->recordGrant('PAYRcat', 'article-one', 1_800, 'sigcat', true);
        $store->withPayerLock('PAYRcat', static fn (): null => null);

        $this->now += 7 * 86_400;

        // This reader, now: a live session and a live grant.
        $store->createSession('PAYRfig', 43_200);
        $store->recordGrant('PAYRfig', 'article-two', 1_800, 'sigfig', true);

        $result = $this->meter($store)->forArticle('PAYRfig', 'article-two', $this->state());

        self::assertSame(MeterOutcome::Granted, $result->outcome, 'served from the grant, without the chain');
        self::assertSame(['PAYRfig/article-two'], $this->column($pdo, "SELECT wallet || '/' || article FROM grants"));
        self::assertSame(['PAYRfig'], $this->column($pdo, 'SELECT wallet FROM sessions'));
        self::assertSame(['PAYRfig'], $this->column($pdo, 'SELECT wallet FROM payers'));
    }

    private function meter(Store $store): Meter
    {
        $config = Config::load(dirname(__DIR__, 2));
        $rpc = new Rpc('http://127.0.0.1:9', $config->program(), timeoutSeconds: 1);

        return new Meter($config, $rpc, new Submitter($rpc, 1_000, 100), $store);
    }

    private function state(): SiteState
    {
        return new SiteState(
            'SITEfig',
            new Site('AUTHfig', 'MINTfig', 'TRESfig', 10_000, 100_000, 500_000, 255),
            null,
            null,
        );
    }

    /** @return list<string> */
    private function column(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);
        $values = array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($values);

        return $values;
    }
}
