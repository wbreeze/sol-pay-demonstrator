<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Auth\Binding;
use SolPay\Core\Blocked;
use SolPay\Core\Fund;
use SolPay\Core\Meter;
use SolPay\Core\Preflight;
use SolPay\Core\Shortfall;
use SolPay\Core\Site;
use SolPay\Core\TokenAccount;

/**
 * What one read of the chain says about the meter a session names, decoded
 * (SPEC §6.2's diagram: "decode Site, Meter, TokenAccount").
 *
 * Two accounts: the meter, and its fund's token account, which holds the money
 * a settle draws on. The fund account itself is not read. The meter names its
 * fund, the session already holds the fund's address, and the balance lives
 * in the token account rather than in the fund.
 *
 * Nothing here is remembered. The site stores a meter, a fund and a key
 * against a session, and reads everything else back from accounts on every
 * request.
 */
final class MeterState
{
    public function __construct(
        public readonly string $meterAddress,
        public readonly string $fund,
        public readonly string $fundTokenAccount,
        public readonly ?Meter $meter,
        public readonly ?TokenAccount $funds,
        private readonly Site $site,
        public readonly int $decimals,
        /** The fund account, decoded. Nothing decides by it; the inspector shows it. */
        public readonly ?Fund $fundAccount = null,
    ) {
    }

    /** No meter account: closed, from this device or another. */
    public function hasMeter(): bool
    {
        return $this->meter !== null;
    }

    /**
     * Whether the session that read this still holds the meter (SPEC §5.3).
     *
     * The meter must exist, must answer to the key the session proved, and
     * must draw on the fund the session recorded. A renewal from another
     * device names that device's key, and this is where this device finds
     * out, at its next read, with no message between the two.
     *
     * The expiry is not part of the answer. An expired meter is still this
     * browser's to renew or to close, so it is `blocked()`'s business rather
     * than the session's.
     */
    public function binds(Binding $binding): bool
    {
        return $this->meter !== null
            && $this->meter->key === $binding->key
            && $this->meter->fund === $binding->fund;
    }

    /** The fund's balance in base units. Zero with no token account. */
    public function balance(): int
    {
        return $this->funds?->amount ?? 0;
    }

    /** The smallest limit a renewal may carry now (`Preflight::limitFloor`). */
    public function limitFloor(): int
    {
        return Preflight::limitFloor($this->site, $this->meter);
    }

    /**
     * Whether metering `$items` more would also transfer, because the unpaid
     * balance would reach the collection threshold. Only a settling call can
     * be refused for a short fund. False with no meter.
     */
    public function willSettle(int $items = 1): bool
    {
        return $this->meter !== null && Preflight::willSettle($this->meter, $this->site, $items);
    }

    /**
     * What a settle metering `$items` more would move: the residue carried,
     * plus their charge. Zero with no meter.
     */
    public function wouldMove(int $items): int
    {
        return $this->meter === null ? 0 : $this->meter->unpaid() + (Preflight::charge($this->site, $items) ?? 0);
    }

    /**
     * How far the fund is short of letting `$items` more go through, in base
     * units: zero when it covers them, and zero when they would not settle at
     * all, because a call that moves nothing cannot be refused for want of
     * money (SPEC §8.2).
     *
     * The second half was missing until 2026-10-01. The offline run that found
     * it settled a meter to an empty fund, and the strip then warned that an
     * advance of 0.07 would be refused, against a threshold of 0.10 it would
     * not reach.
     */
    public function shortOfSettling(int $items): int
    {
        if (!$this->willSettle($items)) {
            return 0;
        }

        $wouldMove = $this->wouldMove($items);

        return $this->funds === null ? $wouldMove : Shortfall::of($this->funds, $wouldMove);
    }

    /** How many more items fit under the limit, or null with no meter. */
    public function itemsRemaining(): ?int
    {
        return $this->meter === null ? null : Preflight::itemsRemaining($this->meter, $this->site);
    }

    /**
     * Whether `$items` more would go through at `$now`, or why not: `Expired`
     * first, then `LimitReached`, in the program's order. Null means it would,
     * and also that there is no meter to ask about.
     */
    public function blocked(int $now, int $items = 1): ?Blocked
    {
        return $this->meter === null ? null : Preflight::canMeter($this->meter, $this->site, $items, $now);
    }
}
