<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Chain\Outcome;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\SubmitStatus;
use Newsprint\Store\Store;

/**
 * SPEC §7.3: find out what became of a charge the article was already served
 * on (2026-09-17).
 *
 * The charging POST records the grant as `Pending` and serves the body as
 * soon as the endpoint accepts the transaction. The confirmation wait — one
 * status request on almost every charge, six asks two seconds apart at worst —
 * moved out of the request the reader is waiting on and into this class,
 * which a later request calls: the page's own follow-up
 * (`POST /a/{slug}/confirm`), or the next `GET /a/{slug}` for a reader
 * without JavaScript. Same shape as {@see CloseFinisher}, and the opposite
 * order, which is the point of `two-orderings`.
 *
 * What it writes:
 *
 * - **Landed.** `Confirmed`.
 * - **Landed and failed.** `Refused`. The grant stays: the reader already has
 *   the article, and the site takes the loss rather than taking it back.
 * - **Not known, and it can no longer land.** `Unknown`, once the settle
 *   window has passed, since a transaction is dead after its blockhash
 *   expires. The grant stays for the same reason.
 * - **Not known yet, or the endpoint did not answer.** Nothing. Still
 *   `Pending`, and the next request asks again.
 *
 * It never removes a grant and never charges. Nothing here can cost a reader
 * anything, which is why it needs no lock: see {@see Store::settleCharge()}.
 */
final class ChargeFinisher
{
    public function __construct(
        private readonly Store $store,
        private readonly int $settleSeconds,
    ) {
    }

    /**
     * @param callable(string): Outcome $ask asks the chain about one signature,
     *                                       once or for a window; may throw
     *                                       {@see RpcException}. Called only
     *                                       when the grant is still pending.
     *
     * @return array{charge: ChargeState, signature: ?string, outcome: ?Outcome}|null
     *                                                                             null when there is no live grant;
     *                                                                             `outcome` is the chain's answer when
     *                                                                             this call asked for one
     */
    public function finish(string $wallet, string $article, callable $ask): ?array
    {
        $grant = $this->store->liveGrant($wallet, $article);
        if ($grant === null) {
            return null;
        }

        $signature = $grant['signature'];
        if ($grant['charge'] !== ChargeState::Pending || $signature === null) {
            return ['charge' => $grant['charge'], 'signature' => $signature, 'outcome' => null];
        }

        try {
            $outcome = $ask($signature);
        } catch (RpcException) {
            // Nothing is decided on a maybe. The endpoint's silence is not the
            // chain's, and a later request asks again.
            return ['charge' => ChargeState::Pending, 'signature' => $signature, 'outcome' => null];
        }

        $charge = match ($outcome->status) {
            SubmitStatus::Confirmed => ChargeState::Confirmed,
            SubmitStatus::Failed => ChargeState::Refused,
            default => $this->store->now() >= $grant['granted_at'] + $this->settleSeconds
                ? ChargeState::Unknown
                : ChargeState::Pending,
        };

        if ($charge !== ChargeState::Pending && !$this->store->settleCharge($wallet, $article, $signature, $charge)) {
            // Somebody else wrote first, or a newer charge replaced the grant.
            // Report the row, which is what the next request will see too.
            $now = $this->store->liveGrant($wallet, $article);
            if ($now === null) {
                return null;
            }

            return ['charge' => $now['charge'], 'signature' => $now['signature'], 'outcome' => null];
        }

        return ['charge' => $charge, 'signature' => $signature, 'outcome' => $outcome];
    }
}
