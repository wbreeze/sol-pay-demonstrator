<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Chain\ChargeFault;
use Newsprint\Chain\Keypair;
use Newsprint\Chain\PayerReader;
use Newsprint\Chain\PayerState;
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

/**
 * SPEC §7: the metering decision, which is the section an integrator comes
 * here for.
 *
 * The library says `can_meter` "reports whether a charge would succeed, not
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
 * - **§7.2 — one meter at a time per payer.** Two requests from one reader
 *   that both reach the metering step build two instructions from the same
 *   read, and the program increments whatever it finds, so both succeed and
 *   the reader pays twice for a race they did not cause. The whole
 *   read-preflight-meter-confirm sequence runs inside a lock on the payer's
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
 *   `page_price`. The errors are not symmetric and the site absorbs the
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
     * Meter one article for one reader, or find that it is already paid for.
     *
     * Everything between the grant check and the grant write happens inside
     * the payer lock (§7.2). The lock is held across an RPC round trip, which
     * is why {@see \Newsprint\Store\Database} sets a busy timeout longer than
     * §7.3's confirmation window.
     */
    public function forArticle(string $wallet, string $article, SiteState $state): MeterResult
    {
        return $this->store->withPayerLock($wallet, function () use ($wallet, $article, $state): MeterResult {
            // §10.4 q.1: expiry only hides a row, and this call is what
            // deletes it. Every request that reaches the metering step sweeps
            // every reader's expired grants and sessions, which is what makes
            // the privacy page's thirty minutes true. A session whose reader
            // never charges waits for the next charge by anyone.
            // `Metering\ChargeSweepsTest` fails without this line.
            $this->store->sweepExpired();

            // Inside the lock, because a request that queued behind another
            // must see the grant that one recorded rather than the state it
            // read before waiting.
            $grant = $this->store->liveGrant($wallet, $article);
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
            $result = $this->meter($wallet, $state, 1, false, ChargeFault::fromEnvironment($this->config->rpcUrl()));

            if ($result->serves()) {
                // §7.3: before the render, not after — and `Pending` until a
                // later request finds out. The lock is released on return, so
                // it is no longer held for the confirmation window.
                $this->store->recordGrant(
                    $wallet,
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
     * SPEC §7.4: meter several views in one instruction, on purpose.
     *
     * No grant is written and none is consulted. This is not a page view; it
     * is the demo control that makes the collection threshold observable in a
     * handful of clicks instead of fifty page loads, and it charges honestly —
     * seven views is seven views, and the transfer that results is real.
     */
    public function advance(string $wallet, SiteState $state, int $pageViews): MeterResult
    {
        return $this->store->withPayerLock(
            $wallet,
            fn (): MeterResult => $this->meter($wallet, $state, $pageViews, true),
        );
    }

    /**
     * The chain part, with the preflight in front of it.
     *
     * The preflight is not a substitute for the program's own check — the
     * program refuses the call regardless — it is what turns a refusal into a
     * screen the reader can act on instead of an error they cannot.
     */
    private function meter(string $wallet, SiteState $state, int $pageViews, bool $wait, ChargeFault $fault = ChargeFault::None): MeterResult
    {
        try {
            $payer = (new PayerReader($this->config, $this->rpc))->read($wallet, $state);
        } catch (RpcException|DecodeException $e) {
            return MeterResult::unreadable($e->getMessage());
        }

        if ($payer->contract === null) {
            // Carried, not discarded. Nothing is sent from here, so the two
            // accounts just read are what the `set-meter` screen is about to
            // draw, and the request has no reason to ask for them twice.
            return MeterResult::unreadable('there is no contract for this reader', $payer);
        }

        $charge = Preflight::charge($state->site, $pageViews) ?? 0;

        $blocked = Preflight::canMeter($payer->contract, $state->site, $pageViews);
        if ($blocked !== null) {
            // Nothing is sent. §8.2's `LimitReached` reaches the reader as
            // `manage_meter` rather than as a failed transaction.
            //
            // **What that saves is a round trip, and only sometimes a fee**
            // (corrected 2026-09-14). `Rpc::sendTransaction` does not pass
            // `skipPreflight`, so the endpoint simulates first and a call this
            // program would refuse is normally rejected there — never
            // included, and therefore free. The fee is charged when the
            // transaction *lands* and then fails, which needs the state to
            // move between that simulation and inclusion: a second tab, a
            // settle arriving, the limit consumed in between. `withPayerLock`
            // narrows that window for one wallet on one deployment and cannot
            // close it.
            //
            // And the fee is **this site's**, not the reader's: the authority
            // is the fee payer on every metering call. The reader pays only
            // for the three transactions their own wallet signs.
            //
            // The payer is carried for the same reason as above, with one
            // gain beyond the round trip: the limit screen now states the
            // arithmetic the refusal was actually made from. Re-reading left
            // open the possibility of a screen that disagreed with the
            // decision it was explaining.
            return MeterResult::blocked($blocked, $charge, $payer);
        }

        $settles = Preflight::willSettle($payer->contract, $state->site, $pageViews);
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
                $wallet,
                $payer->tokenAccount,
                $addresses['treasury'],
                $addresses['mint'],
                $pageViews,
            ),
        ];

        $outcome = $this->submitter->send($instructions, $authority, wait: $wait, fault: $fault);

        if ($outcome->status === SubmitStatus::Sent) {
            // No payer, as below: the chain has moved, or is about to.
            return MeterResult::servedAhead((string) $outcome->signature, $charge, $settles, $instructions);
        }

        if ($outcome->status === SubmitStatus::Confirmed) {
            return MeterResult::metered((string) $outcome->signature, $charge, $settles, $pageViews, $instructions);
        }

        if ($outcome->status === SubmitStatus::Unconfirmed) {
            return MeterResult::unconfirmed((string) $outcome->signature, $charge, $settles, $pageViews, $instructions);
        }

        // **No payer is carried past this point.** Everything below follows a
        // `sendTransaction`, so the accounts read at the top of this method
        // may no longer describe the chain, and §2's claim 7 says the screen's
        // numbers come from an account rather than from what the server
        // remembers. The request reads again, and that second read is bought
        // on purpose.
        //
        // Failed. §8.2: `InsufficientFunds` is ambiguous by construction — SPL
        // reports a short balance and a short allowance identically, and the
        // two need opposite responses. So the account is read rather than the
        // code guessed at, which is what `Shortfall` is a struct and not a
        // verdict for.
        return MeterResult::failed(
            $outcome->detail,
            $outcome->cause,
            $this->shortfall($payer, $payer->contract->unpaid() + $charge),
            $outcome->signature,
            $instructions,
            // Asked of the same read the shortfall came from. The library's
            // `delegatePresent` cannot answer it: a delegate another site's
            // `approve` installed is present and is not ours.
            $payer->delegateIsContract(),
        );
    }

    private function shortfall(PayerState $payer, int $unpaid): ?Shortfall
    {
        return $payer->funds === null ? null : Shortfall::diagnose($payer->funds, $unpaid);
    }
}
