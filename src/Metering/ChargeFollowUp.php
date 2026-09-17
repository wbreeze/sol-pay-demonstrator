<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Chain\Outcome;
use Newsprint\Chain\ProgramEvent;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\Submitter;
use Newsprint\Store\Store;
use Newsprint\Support\Config;

/**
 * Confirm afterward (SPEC §7.3, 2026-09-17).
 *
 * {@see Meter} serves an article as soon as the endpoint accepts its charge.
 * This is the rest: asking the cluster what became of that charge, writing
 * the answer onto the grant, and turning it into a {@see MeterResult} a page
 * can report. It holds no keypair and builds no instruction, so nothing it
 * does can charge anybody.
 */
final class ChargeFollowUp
{
    public function __construct(
        private readonly Config $config,
        private readonly Rpc $rpc,
        private readonly Submitter $submitter,
        private readonly Store $store,
    ) {
    }

    /**
     * SPEC §7.3's second half: what became of the charge an article was served
     * on. Null when the reader holds no live grant for it.
     *
     * Two callers, and `$wait` is the difference:
     *
     * - **`POST /a/{slug}/confirm`**, which the page sends by itself once the
     *   article is on screen, waits for the window and then says what
     *   happened. When the charge landed it also reads the charge's event —
     *   one `getTransaction` — because "this one settled" is a claim about the
     *   chain, and the request that predicted it is gone.
     * - **`GET /a/{slug}`** asks once and does not wait. That is what finishes
     *   the job for a reader without JavaScript, and it costs a request with a
     *   grant nothing at all once the charge is settled either way.
     *
     * No payer lock. Nothing here charges, the grant is never removed, and
     * {@see Store::settleCharge()} is conditional — see {@see ChargeFinisher}.
     * That is also why this is a class of its own rather than a method on
     * {@see Meter}: the GET can be given this and not the thing that charges,
     * and `SafeMethodTest` can keep saying so without an exception.
     */
    public function report(string $wallet, string $article, bool $wait): ?MeterResult
    {
        $finisher = new ChargeFinisher($this->store, (int) $this->config->metering()['charge_settle_s']);

        // History search: this can be asked minutes after the send, and the
        // status cache alone would answer "never heard of it" by then.
        $found = $finisher->finish(
            $wallet,
            $article,
            $wait
                ? fn (string $signature): Outcome => $this->submitter->confirm($signature, true)
                : fn (string $signature): Outcome => $this->submitter->check($signature, true),
        );

        if ($found === null) {
            return null;
        }

        $signature = $found['signature'];
        if (!$wait || $signature === null) {
            return MeterResult::granted($found['charge'], $found['outcome'] !== null);
        }

        return match ($found['charge']) {
            ChargeState::Confirmed => $this->confirmedLater($signature),
            ChargeState::Refused => MeterResult::absorbed(
                $signature,
                $found['outcome']?->cause,
                $found['outcome']->detail ?? 'transaction failed on chain',
            ),
            ChargeState::Pending, ChargeState::Unknown => MeterResult::unconfirmedLater($signature, $found['charge']),
        };
    }

    /**
     * A landed charge, reported with what its event says. The event not
     * answering is not a reason to withhold the report — only the sentence
     * about settling depends on it, and that sentence is left out.
     */
    private function confirmedLater(string $signature): MeterResult
    {
        try {
            $logs = $this->rpc->transactionLogs($signature);
        } catch (RpcException) {
            $logs = null;
        }

        $event = $logs === null ? null : ProgramEvent::fromLogs($logs);
        if ($event === null || $event->name !== 'Metered') {
            return MeterResult::confirmedLater($signature, null);
        }

        return MeterResult::confirmedLater(
            $signature,
            (int) ($event->fields['transferred'] ?? 0) > 0,
            (int) ($event->fields['page_views'] ?? 1),
        );
    }
}
