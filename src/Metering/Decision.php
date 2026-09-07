<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Content\Piece;

/**
 * SPEC §7.5, and it is one function on purpose.
 *
 * `wasm-client/SPEC.md` §4.4 warns that a contract is not a viewer type, and
 * that keying access off "has a contract" eventually charges a subscriber for
 * something their subscription already covers. This demo has no subscriptions,
 * so it cannot demonstrate the coexistence — but the decision point exists
 * anyway, in one named place, returning true for every article here.
 *
 * **This is where an integrator's entitlement check goes.** A publisher with
 * subscribers answers false here for a subscriber and never reaches the chain;
 * a publisher with a free tier answers false for the first three articles of
 * the month. Nothing else in the metering path has to know.
 */
final class Decision
{
    /**
     * Should this request be metered at all?
     *
     * The signature takes the reader as well as the piece, because every real
     * implementation of this needs both and a function that takes only the
     * article teaches the wrong shape.
     */
    public static function shouldMeter(Piece $piece, ?string $wallet): bool
    {
        // Unmetered content — the privacy page, anything §10.1 marks free —
        // never reaches the chain regardless of who is reading.
        if (!$piece->metered) {
            return false;
        }

        // And this is the line that would carry an entitlement check.
        // Newsprint has none: everyone who reads a metered article pays for it.
        return true;
    }
}
