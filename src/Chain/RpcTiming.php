<?php

declare(strict_types=1);

namespace Newsprint\Chain;

/**
 * How many RPC round trips this request made, and how long each took.
 *
 * **Why this exists.** SPEC §12.4 says a metered page view costs about three
 * calls. On 2026-09-09 a browser profile timed a seven-view advance at 4.07 s
 * from click to `DOMContentLoaded` — 2.87 s in `POST /meter/advance`, 1.06 s in
 * the article it redirects to — and the three-call decomposition of that 2.87 s
 * was *inference from the timing*, not a measurement. The endpoint is the only
 * thing that knows, and nothing was asking it.
 *
 * **It is per-request and it is never stored.** §10.4 enumerates what this site
 * keeps and a per-wallet record of chain activity is exactly the reading
 * history the design exists to refuse. This is a counter that lives for the
 * length of one request and dies with the process — the same standing as
 * `MeterResult`, and for the same reason.
 *
 * **It is reported in a response header, not a log file**, because the artefact
 * that already carries the timings is a browser HAR or a Gecko profile, and a
 * count in a different file is a count somebody has to correlate by hand. A
 * header lands in the same capture as the number it explains — including on the
 * 303 from `/meter/advance`, which is where the interesting half is.
 *
 * **Off unless asked**, by environment rather than by config: a diagnostic that
 * a committed config value can turn on is one that eventually ships turned on,
 * and this one names internal call counts. `NEWSPRINT_RPC_TIMING=1 bin/run-dev`.
 */
final class RpcTiming
{
    /** @var list<array{method: string, ms: float}> */
    private static array $calls = [];

    public static function enabled(): bool
    {
        return getenv('NEWSPRINT_RPC_TIMING') === '1';
    }

    public static function record(string $method, float $ms): void
    {
        // Recorded whether or not reporting is enabled: the cost is one array
        // push per round trip, and a counter that only counts when someone is
        // watching cannot answer "was it already like this?".
        self::$calls[] = ['method' => $method, 'ms' => $ms];
    }

    public static function count(): int
    {
        return count(self::$calls);
    }

    public static function totalMs(): float
    {
        return array_sum(array_column(self::$calls, 'ms'));
    }

    /**
     * `getMultipleAccounts=912 sendTransaction=1004 getSignatureStatuses=955`,
     * in the order the calls were made — repeats included and not summed,
     * because "the confirmation poll ran nine times" is the answer to a
     * question this exists to ask.
     */
    public static function detail(): string
    {
        return implode(' ', array_map(
            static fn (array $c): string => sprintf('%s=%d', $c['method'], (int) round($c['ms'])),
            self::$calls,
        ));
    }

    /** For tests, which must not inherit another test's calls. */
    public static function reset(): void
    {
        self::$calls = [];
    }
}
