<?php

declare(strict_types=1);

namespace Newsprint\Tests\Metering;

use PHPUnit\Framework\TestCase;

/**
 * SPEC §7.2, the oldest open item in this repository until 2026-09-12.
 *
 * Two requests from one reader that both reach the metering step build two
 * `meter_and_settle` instructions from the same read. They do not conflict on
 * chain — the program increments whatever it finds — so both succeed and the
 * reader is charged twice for a race they did not cause. The server prevents
 * it by serializing the read-preflight-meter-confirm sequence per wallet, in
 * a SQLite transaction taken with `BEGIN IMMEDIATE`.
 *
 * **What SPEC asks for, and what this is.** §7.2 says the test is two
 * browsers, one wallet, one article, and the pass condition is one
 * `meter_and_settle` on chain. That needs devnet, a funded payer and a real
 * wallet, and it is not something CI can ever hold — which is why the line sat
 * unwritten from the beginning. What CI *can* hold is the half that carries
 * the defect: the serialization itself, under real contention, in real
 * processes, against the real lock. The chain half stays an observation
 * someone makes on devnet.
 *
 * So this file is in two parts, and neither is worth much without the other:
 *
 * 1. **The race**, below. Four separate PHP processes, one wallet, one
 *    article, one SQLite file, entering together. Exactly one does the work;
 *    the other three find it done. Then the same four with the lock removed
 *    and nothing else changed, which must produce the double charge — a race
 *    test that cannot fail is not a test, and this one says so out loud.
 * 2. **The structure**, after it. The race exercises `Store::withPayerLock`.
 *    It says nothing about whether `Meter::forArticle` still *uses* it, or
 *    still does its grant check inside it rather than in front of it. That is
 *    positional, so the check is too — the same shape as
 *    `Support\FrontControllerTest`.
 *
 * It costs about a second and a half of wall clock, nearly all of it waiting
 * on purpose. That is the price of the only test here that runs more than one
 * process, and §7.2 is not provable in one.
 */
final class OneMeterAtATimeTest extends TestCase
{
    /** Long enough that four processes are certainly inside it together. */
    private const HOLD_US = 150_000;

    private const WORKERS = 4;

    private string $db = '';

    private string $barrier = '';

    protected function setUp(): void
    {
        $db = tempnam(sys_get_temp_dir(), 'newsprint-race-');
        self::assertIsString($db);
        // Database::open() creates the schema; it must be a real file, since
        // the whole question is what two processes see of each other, and
        // `:memory:` gives each of them a private answer.
        unlink($db);
        $this->db = $db.'.sqlite';

        // The workers rendezvous here rather than on a clock; see the worker.
        $this->barrier = $db.'.barrier';
        mkdir($this->barrier, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->db, $this->db.'-wal', $this->db.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ((array) glob($this->barrier.'/*') as $marker) {
            unlink((string) $marker);
        }
        if (is_dir($this->barrier)) {
            rmdir($this->barrier);
        }
    }

    public function testOneOfFourSimultaneousRequestsMetersAndTheRestFindTheGrant(): void
    {
        $outcomes = $this->race('locked');

        self::assertCount(self::WORKERS, $outcomes, 'every worker answered');
        self::assertSame(1, $this->tally($outcomes, 'metered'), 'one charge: '.implode(' ', $outcomes));
        self::assertSame(
            self::WORKERS - 1,
            $this->tally($outcomes, 'granted'),
            'the rest served from that grant: '.implode(' ', $outcomes),
        );
    }

    /**
     * The control, and the reason the test above means anything.
     *
     * Identical work, identical timing, no transaction around it. If this ever
     * passes with one `metered`, the race is not racing and the test above has
     * been reporting a fact about scheduling rather than about the lock.
     */
    public function testWithoutTheLockTheSameFourRequestsChargeFourTimes(): void
    {
        $outcomes = $this->race('unlocked');

        self::assertGreaterThan(
            1,
            $this->tally($outcomes, 'metered'),
            'the unlocked control must double-charge, or the locked case proves nothing: '.implode(' ', $outcomes),
        );
    }

    /**
     * §7.4's advance takes the same lock. It writes no grant and consults
     * none, so the race above cannot cover it — but it charges the chain, and
     * two of them from one reader is the same defect wearing a different hat.
     */
    public function testAdvanceIsInsideTheLockToo(): void
    {
        $body = $this->methodBody('advance');

        self::assertSame(
            'return',
            trim(substr($body, 0, (int) strpos($body, '$this->store->withPayerLock('))),
            'SPEC §7.4 charges the chain; it queues behind the same lock, first thing',
        );
    }

    /**
     * Nothing happens before the lock, and the grant check happens inside it.
     *
     * A grant check in front of the lock is the exact defect §7.2 describes:
     * both requests read "no grant", both queue, and the second meters anyway
     * because it decided before it waited. The race cannot see that — it
     * exercises `Store`, and this is about `Meter` — so it is asserted here,
     * positionally, the way `FrontControllerTest` asserts its captures.
     */
    public function testForArticleDecidesInsideTheLockAndNotBeforeIt(): void
    {
        $body = $this->methodBody('forArticle');

        $lock = strpos($body, '$this->store->withPayerLock(');
        self::assertIsInt($lock, 'forArticle takes the payer lock');

        // Everything before the call must be the `return` that hands it back.
        // Any other statement there is a decision made before waiting, which
        // is the defect itself.
        self::assertSame(
            'return',
            trim(substr($body, 0, $lock)),
            'nothing runs before the lock is taken: '.trim(substr($body, 0, $lock)),
        );

        foreach (['liveGrant(', 'recordGrant(', 'countPurchase('] as $call) {
            $at = strpos($body, $call);
            self::assertIsInt($at, "forArticle calls {$call}");
            self::assertGreaterThan($lock, $at, "{$call} is inside the lock, not in front of it");
        }
    }

    // ---- the harness -------------------------------------------------------

    /**
     * Start `self::WORKERS` processes and collect what each one printed. They
     * rendezvous at the barrier themselves, so nothing here waits on a clock.
     *
     * @return list<string>
     */
    private function race(string $mode): array
    {
        $worker = __DIR__.'/one-meter-at-a-time-worker.php';

        $procs = [];
        foreach (range(1, self::WORKERS) as $_) {
            $command = [
                PHP_BINARY, $worker, $this->db, 'PAYRfig', 'why-approve-comes-first',
                $this->barrier, (string) self::WORKERS, (string) self::HOLD_US, $mode,
            ];
            $pipes = [];
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($proc, 'could not start a worker');
            $procs[] = [$proc, $pipes];
        }

        $outcomes = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = trim((string) stream_get_contents($pipes[1]));
            $err = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($proc);

            self::assertSame(0, $status, "a worker failed ({$mode}): {$err}");
            self::assertContains($out, ['metered', 'granted'], "a worker said something else: {$out} {$err}");
            $outcomes[] = $out;
        }

        return $outcomes;
    }

    /** @param list<string> $outcomes */
    private function tally(array $outcomes, string $word): int
    {
        return count(array_filter($outcomes, static fn (string $o): bool => $o === $word));
    }

    /**
     * One method's body: after its opening brace, up to the next member.
     *
     * Textual on purpose. The claims above are about where a call sits among
     * the statements around it, and a reflection API answers about what the
     * method *is* rather than about the order it is written in.
     */
    private function methodBody(string $name): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Metering/Meter.php');

        $from = strpos($source, "public function {$name}(");
        self::assertIsInt($from, "Meter::{$name}() exists");

        $open = strpos($source, "\n    {\n", $from);
        self::assertIsInt($open, "Meter::{$name}() has a body on the next line");

        $rest = substr($source, $open + 7);
        $next = preg_split('/\n    (?:\/\*\*|(?:private|public|protected) function )/', $rest, 2);
        self::assertIsArray($next);

        return $next[0];
    }
}
