<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * **One schedule for asking whether a transaction landed** (2026-09-17).
 *
 * The site used to poll every 500 ms inside a 20-second window, written out
 * three times: once in `Submitter` and once in each of the two routes that
 * confirm a transaction the reader's wallet sent. It now asks a stated number
 * of times, a stated interval apart (`confirm_attempts`,
 * `confirm_spacing_ms`), in one method. This keeps it that way: a second loop
 * over `getSignatureStatuses` anywhere else is a second schedule nobody
 * configured.
 *
 * Textual, because `Rpc` is final and the property is where the loop lives.
 */
final class ConfirmScheduleTest extends TestCase
{
    public function testTheScheduleIsStatedAsAsksAndSpacing(): void
    {
        $rpc = Config::load(dirname(__DIR__, 2))->rpc();

        self::assertArrayNotHasKey('confirm_timeout_ms', $rpc, 'a window is not a schedule');
        self::assertArrayNotHasKey('confirm_poll_ms', $rpc);
        self::assertGreaterThanOrEqual(1, (int) $rpc['confirm_attempts']);
        self::assertGreaterThan(0, (int) $rpc['confirm_spacing_ms']);

        // Bounded in requests as well as time: this is what "not a poll" means.
        $worstCaseMs = ((int) $rpc['confirm_attempts'] - 1) * (int) $rpc['confirm_spacing_ms'];
        self::assertLessThanOrEqual(20_000, $worstCaseMs, 'no longer than the window it replaced');
        self::assertLessThanOrEqual(10, (int) $rpc['confirm_attempts'], 'a handful of asks, not a stream');
    }

    public function testOnlyTheSubmitterAsksForSignatureStatuses(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            ...glob($root.'/src/*/*.php') ?: [],
            $root.'/public/index.php',
            ...glob($root.'/bin/*') ?: [],
        ];

        $askers = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, '->signatureStatuses(')) {
                $askers[] = substr($file, strlen($root) + 1);
            }
        }

        self::assertSame(['src/Chain/Submitter.php'], $askers);

        // And nothing in the front controller waits on a clock.
        self::assertStringNotContainsString('usleep(', (string) file_get_contents($root.'/public/index.php'));
    }
}
