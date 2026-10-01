<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Auth\Binding;
use Newsprint\Chain\ChargeFault;
use Newsprint\Chain\Keypair;
use Newsprint\Chain\MeterReader;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\SiteState;
use Newsprint\Chain\SubmitStatus;
use Newsprint\Chain\Submitter;
use Newsprint\Store\Store;
use Newsprint\Support\Config;
use SolPay\Core\DecodeException;
use SolPay\Core\Ix;
use SolPay\Core\Preflight;
use SolPay\Core\Shortfall;
use SolPay\Core\TokenAccount;

/**
 * SPEC §7: the metering decision, which is the section an integrator comes
 * here for.
 *
 * The library says preflight "reports whether a charge would succeed, not
 * whether it should happen. Only the site knows that." Everything in this
 * class is the site knowing it.
 *
 * Four rules, and each one is a policy this demo chose rather than something
 * sol-pay imposed:
 *
 * - **§7.1 — one charge per article, not per request.** A refresh is a
 *   request. So is a back button, a prefetch, a bot, and the inspector
 *   re-reading its own panel. A request that finds a live grant never touches
 *   the chain. Metering per request is "a billing bug with a chain
 *   underneath".
 * - **§7.2 — one charge at a time per meter.** Two requests on one session
 *   that both reach the metering step build two instructions from the same
 *   read, and the program increments whatever it finds, so both succeed and
 *   the reader pays twice for a race they did not cause. The whole
 *   read-preflight-meter-record sequence runs inside a lock on the meter's
 *   row, so the second request finds the grant the first recorded.
 * - **§7.3 — meter, record, render, then confirm** (2026-09-17; until then
 *   the confirmation came second). The grant is recorded before the body is,
 *   so a render failure still leaves the reader holding what they paid for,
 *   and the body is served as soon as the endpoint has accepted the charge.
 *   Finding out whether it landed is {@see ChargeFollowUp}, on a later
 *   request.
 * - **§7.3 again — a charge that has not confirmed serves the article, and
 *   so does one that turns out to have failed.** Refusing risks charging a
 *   reader for nothing; serving risks giving away one article at
 *   `item_price`. The errors are not symmetric and the site absorbs the
 *   cheaper one, deliberately and in writing.
 */
final class Meter
{
    public function __construct(
        private readonly Config $config,
        private readonly Rpc $rpc,
        private readonly Submitter $submitter,
        private readonly Store $store,
    ) {
    }

    /**
     * Meter one article on one meter, or find that it is already paid for.
     *
     * Everything between the grant check and the grant write happens inside
     * the meter lock (§7.2). The lock is held across an RPC round trip, which
     * is why {@see \Newsprint\Store\Database} sets a busy timeout longer than
     * §7.3's confirmation window.
     */
    public function forArticle(Binding $binding, string $article, SiteState $state): MeterResult
    {
        return $this->store->withMeterLock($binding->meter, function () use ($binding, $article, $state): MeterResult {
            // §10.4 q.1: expiry only hides a row, and this call is what
            // deletes it. Every request that reaches the metering step sweeps
            // every meter's expired grants and sessions, which is what makes
            // the privacy page's thirty minutes true. A session whose reader
            // never charges waits for the next charge by anyone.
            // `Metering\ChargeSweepsTest` fails without this line.
            $this->store->sweepExpired();

            // Inside the lock, because a request that queued behind another
            // must see the grant that one recorded rather than the state it
            // read before waiting.
            $grant = $this->store->liveGrant($binding->meter, $article);
            if ($grant !== null) {
                // With what became of its charge, which may still be pending:
                // a resubmitted form or a second tab can arrive before the
                // chain has answered, and the page must not claim otherwise.
                return MeterResult::granted($grant['charge']);
            }

            // §7.3: not waited for. The endpoint simulates before it
            // forwards, so the charges it would refuse are refused here, with
            // nothing served and nothing recorded; the rest are served now.
            // (Unless a devnet test fault is on — see `ChargeFault`.)
            $result = $this->meter($binding, $state, 1, false, ChargeFault::fromEnvironment($this->config->rpcUrl()));

            if ($result->serves()) {
                // §7.3: before the render, not after — and `Pending` until a
                // later request finds out. The lock is released on return, so
                // it is no longer held for the confirmation window.
                $this->store->recordGrant(
                    $binding->meter,
                    $article,
                    (int) $this->config->metering()['grant_ttl_s'],
                    $result->signature,
                    $result->chargeState,
                );
                // §10.4 qualification 5: a fact about the article, incremented
                // here and never derived from `grants`.
                $this->store->countPurchase($article);
            }

            return $result;
        });
    }

    /**
     * SPEC §7.4: meter several items in one instruction, on purpose.
     *
     * No grant is written and none is consulted. This is not a page view; it
     * is the demo control that makes the collection threshold observable in a
     * handful of clicks instead of fifty page loads, and it charges honestly —
     * seven views is seven views, and the transfer that results is real.
     */
    public function advance(Binding $binding, SiteState $state, int $items): MeterResult
    {
        return $this->store->withMeterLock(
            $binding->meter,
            fn (): MeterResult => $this->meter($binding, $state, $items, true),
        );
    }

    /**
     * The chain part, with the preflight in front of it.
     *
     * The preflight is not a substitute for the program's own check — the
     * program refuses the call regardless — it is what turns a refusal into a
     * screen the reader can act on instead of an error they cannot.
     */
    private function meter(Binding $binding, SiteState $state, int $items, bool $wait, ChargeFault $fault = ChargeFault::None): MeterResult
    {
        try {
            $read = (new MeterReader($this->config, $this->rpc))->read($binding, $state);
        } catch (RpcException|DecodeException $e) {
            return MeterResult::unreadable($e->getMessage());
        }

        // SPEC §5.3, asked again inside the lock. The middleware asked of the
        // read in front of it, and a renewal from another device can land in
        // between. The program would still take this charge, because
        // `meter_and_settle` is the authority's alone; whether this browser
        // may still draw on the meter is the site's question.
        if (!$read->binds($binding) || $read->meter === null) {
            return MeterResult::unbound($read);
        }

        $charge = Preflight::charge($state->site, $items) ?? 0;

        $blocked = Preflight::canMeter($read->meter, $state->site, $items, $this->store->now());
        if ($blocked !== null) {
            // Nothing is sent. §8.2's `Expired` and `LimitReached` reach the
            // reader as `manage_meter` rather than as a failed transaction.
            //
            // **What that saves is a round trip, and only sometimes a fee**
            // (corrected 2026-09-14). `Rpc::sendTransaction` does not pass
            // `skipPreflight`, so the endpoint simulates first and a call this
            // program would refuse is normally rejected there — never
            // included, and therefore free. The fee is charged when the
            // transaction *lands* and then fails, which needs the state to
            // move between that simulation and inclusion. `withMeterLock`
            // narrows that window for one meter on one deployment and cannot
            // close it.
            //
            // And the fee is **this site's**, not the reader's: the authority
            // is the fee payer on every metering call.
            //
            // The read is carried, so the limit screen states the arithmetic
            // the refusal was actually made from.
            return MeterResult::blocked($blocked, $charge, $read, $items);
        }

        $settles = Preflight::willSettle($read->meter, $state->site, $items);
        $addresses = $this->config->provisioned();
        $authority = Keypair::load($this->config->keypairPath('authority'));

        // Built once and kept, rather than built inside the send call. §9's
        // last section shows these bytes as evidence, and evidence that was
        // reconstructed for the display would only ever agree with itself.
        $instructions = [
            Ix::meterAndSettle(
                $this->config->program(),
                $state->address,
                $authority->address,
                $binding->fund,
                $addresses['treasury'],
                $addresses['mint'],
                $items,
            ),
        ];

        $outcome = $this->submitter->send($instructions, $authority, wait: $wait, fault: $fault);

        if ($outcome->status === SubmitStatus::Sent) {
            // No read carried, as below: the chain has moved, or is about to.
            return MeterResult::servedAhead((string) $outcome->signature, $charge, $settles, $instructions);
        }

        if ($outcome->status === SubmitStatus::Confirmed) {
            return MeterResult::metered((string) $outcome->signature, $charge, $settles, $items, $instructions);
        }

        if ($outcome->status === SubmitStatus::Unconfirmed) {
            return MeterResult::unconfirmed((string) $outcome->signature, $charge, $settles, $items, $instructions);
        }

        // **No read is carried past this point.** Everything below follows a
        // `sendTransaction`, so the accounts read at the top of this method
        // may no longer describe the chain, and §2's claim 7 says the screen's
        // numbers come from an account rather than from what the server
        // remembers. The request reads again, and that second read is bought
        // on purpose.
        //
        // Failed. §8.2: under the fund design `InsufficientFunds` means one
        // thing, that the fund holds less than the unpaid total, and the
        // shortfall is how much less. It is worked out from the read that
        // decided to send, which is the balance the settle was refused
        // against, give or take a deposit in the same second.
        return MeterResult::failed(
            $outcome->detail,
            $outcome->cause,
            $this->shortfall($read->funds, $read->meter->unpaid() + $charge),
            $outcome->signature,
            $instructions,
            $items,
        );
    }

    private function shortfall(?TokenAccount $funds, int $unpaid): ?int
    {
        return $funds === null ? null : Shortfall::of($funds, $unpaid);
    }

}
