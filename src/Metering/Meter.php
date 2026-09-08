<?php

declare(strict_types=1);

namespace Newsprint\Metering;

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
 * - **§7.3 — meter, confirm, record, then render.** The grant is recorded
 *   before the body is, so a render failure still leaves the reader holding
 *   what they paid for.
 * - **§7.3 again — an unconfirmed transaction serves the article.** Refusing
 *   risks charging a reader for nothing; serving risks giving away one article
 *   at `page_price`. The errors are not symmetric and the site absorbs the
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
            // Inside the lock, because a request that queued behind another
            // must see the grant that one recorded rather than the state it
            // read before waiting.
            if ($this->store->liveGrant($wallet, $article) !== null) {
                return MeterResult::granted();
            }

            $result = $this->meter($wallet, $state, 1);

            if ($result->serves()) {
                // §7.3: before the render, not after.
                $this->store->recordGrant(
                    $wallet,
                    $article,
                    (int) $this->config->metering()['grant_ttl_s'],
                    $result->signature,
                    $result->outcome !== MeterOutcome::Unconfirmed,
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
            fn (): MeterResult => $this->meter($wallet, $state, $pageViews),
        );
    }

    /**
     * The chain part, with the preflight in front of it.
     *
     * The preflight is not a substitute for the program's own check — the
     * program refuses the call regardless — it is what turns a refusal into a
     * screen the reader can act on instead of a failed transaction and a fee.
     */
    private function meter(string $wallet, SiteState $state, int $pageViews): MeterResult
    {
        try {
            $payer = (new PayerReader($this->config, $this->rpc))->read($wallet, $state);
        } catch (RpcException|DecodeException $e) {
            return MeterResult::unreadable($e->getMessage());
        }

        if ($payer->contract === null) {
            return MeterResult::unreadable('there is no contract for this reader');
        }

        $charge = Preflight::charge($state->site, $pageViews) ?? 0;

        $blocked = Preflight::canMeter($payer->contract, $state->site, $pageViews);
        if ($blocked !== null) {
            // Nothing is sent. §8.2's `LimitReached` reaches the reader as
            // `manage_meter` rather than as a failed transaction they paid a
            // fee for.
            return MeterResult::blocked($blocked, $charge);
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

        $outcome = $this->submitter->send($instructions, $authority);

        if ($outcome->status === SubmitStatus::Confirmed) {
            return MeterResult::metered((string) $outcome->signature, $charge, $settles, $pageViews, $instructions);
        }

        if ($outcome->status === SubmitStatus::Unconfirmed) {
            return MeterResult::unconfirmed((string) $outcome->signature, $charge, $settles, $pageViews, $instructions);
        }

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
        );
    }

    private function shortfall(PayerState $payer, int $unpaid): ?Shortfall
    {
        return $payer->funds === null ? null : Shortfall::diagnose($payer->funds, $unpaid);
    }
}
