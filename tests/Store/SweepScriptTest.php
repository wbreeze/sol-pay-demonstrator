<?php

declare(strict_types=1);

namespace Newsprint\Tests\Store;

use Newsprint\Store\Database;
use Newsprint\Store\Store;
use PHPUnit\Framework\TestCase;

/**
 * `bin/sweep`, the scheduled half of §10.4 q.1.
 *
 * The charge-time sweep has `Metering\ChargeSweepsTest`. This test covers the
 * half that runs when nobody is buying: the script, run as cron runs it, as
 * its own process against a real file.
 */
final class SweepScriptTest extends TestCase
{
    private string $db = '';

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'newsprint-sweep-');
        self::assertIsString($tmp);
        unlink($tmp);
        $this->db = $tmp.'.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->db, $this->db.'-wal', $this->db.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testTheScheduledSweepDeletesExpiredRowsAndKeepsLiveOnes(): void
    {
        $weekAgo = time() - 7 * 86_400;
        $old = new Store(Database::open($this->db), static fn (): int => $weekAgo);
        $old->createSession('PAYRcat', 3_600);
        $old->recordGrant('PAYRcat', 'article-one', 1_800);

        $store = new Store(Database::open($this->db));
        $store->recordGrant('PAYRfig', 'article-two', 1_800);

        [$status, $out, $err] = $this->sweep('--verbose', $this->db);

        self::assertSame(0, $status, $err);
        self::assertSame("swept 1 grant(s), 1 session(s), 0 lock row(s), 0 nonce(s), 0 pending close(s)\n", $out);
        self::assertNull($store->oldestExpired(), 'nothing expired is left waiting');
        self::assertNotNull($store->liveGrant('PAYRfig', 'article-two'), 'and the live grant is untouched');
    }

    public function testItIsSilentWhenItSucceeds(): void
    {
        Database::open($this->db);

        self::assertSame([0, '', ''], $this->sweep($this->db), 'cron mails whatever a job prints');
    }

    public function testAMissingDatabaseIsNotCreated(): void
    {
        self::assertSame([0, '', ''], $this->sweep($this->db));
        self::assertFileDoesNotExist($this->db);
    }

    public function testAnUnknownFlagIsRefused(): void
    {
        [$status, , $err] = $this->sweep('--quiet', $this->db);

        self::assertSame(2, $status);
        self::assertStringContainsString('usage:', $err);
    }

    /** @return array{int, string, string} */
    private function sweep(string ...$args): array
    {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/sweep', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'could not start bin/sweep');

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
