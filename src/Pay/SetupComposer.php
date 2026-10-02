<?php

declare(strict_types=1);

namespace Newsprint\Pay;

use Newsprint\Chain\AssociatedToken;
use SolPay\Core\Fund;
use SolPay\Core\Ix;
use SolPay\Core\Meter;
use SolPay\Core\Pda;
use SolPay\Core\Preflight;
use SolPay\Core\Program;
use SolPay\Core\Site;
use SolPay\Core\TokenAccount;
use SolPay\Core\Units;

/**
 * SPEC §6.3's composition rules, and its refusals, in one place a test can
 * reach without a chain. `bin/fund-trials` worked them out on devnet on
 * 2026-09-30; they moved here so that the route and the trial cannot differ.
 *
 * The server reads, in one call, the fund the wallet and the index derive,
 * this site's meter on that fund, and the wallet's own token account
 * ({@see addresses()}). Then:
 *
 * - **no fund:** `open_fund` at the index, which must come before the
 *   deposit, since the fund's token account has to exist first;
 * - **a deposit of more than zero:** the transfer from the wallet's token
 *   account into the fund;
 * - **no meter:** `open_meter`, naming the pending key, limit and expiry;
 * - **a meter:** `renew_meter` with the same three, which is how a second
 *   device takes the meter over (§6.4).
 *
 * *Add to the fund* composes the transfer and nothing else.
 *
 * Refused before the wallet sees anything: a deposit the wallet cannot cover,
 * a limit below the floor, and a fund that belongs to another wallet. The
 * expiry is never in the past, because it is a choice counted from now.
 */
final class SetupComposer
{
    public function __construct(
        private readonly Program $program,
        private readonly string $site,
        private readonly Site $siteAccount,
        private readonly int $decimals,
        private readonly string $symbol,
    ) {
    }

    /**
     * The fund, the meter and the wallet's token account, in the order
     * {@see compose()} expects them read.
     *
     * @return array{fund: string, meter: string, holding: string}
     */
    public function addresses(string $account, int $index): array
    {
        $mint = $this->siteAccount->mint;
        $fund = Pda::fundAddress($account, $mint, $index, $this->program->id)['address'];

        return [
            'fund' => $fund,
            'meter' => Pda::meterAddress($this->site, $fund, $this->program->id)['address'],
            'holding' => AssociatedToken::address($account, $mint, $this->program->tokenProgram),
        ];
    }

    public function compose(
        SetupAnswers $answers,
        string $account,
        ?Fund $fund,
        ?Meter $meter,
        ?TokenAccount $holding,
        int $now,
    ): Composition|SetupRefusal {
        $mint = $this->siteAccount->mint;
        $at = $this->addresses($account, $answers->index);

        if ($answers->fund !== null && $answers->fund !== $at['fund']) {
            return SetupRefusal::anotherWallet();
        }

        // The rule devnet taught twice on 2026-09-30: the server knows the
        // wallet when it composes, so it reads the wallet's balance and never
        // composes a deposit the wallet cannot cover.
        $held = $holding?->amount ?? 0;
        if ($answers->deposit > $held) {
            return SetupRefusal::depositShort($this->amount($answers->deposit), $this->amount($held), $this->symbol);
        }

        $instructions = [];
        $plan = [];

        if ($answers->kind === SetupAnswers::DEPOSIT) {
            if ($fund === null) {
                return SetupRefusal::noFund($answers->index);
            }
            $instructions[] = Ix::deposit($this->program, $at['holding'], $account, $at['fund'], $mint, $answers->deposit, $this->decimals);

            return new Composition($instructions, ['add '.$this->amount($answers->deposit).' '.$this->symbol.' to fund '.$answers->index], $at['fund'], $at['meter']);
        }

        $floor = Preflight::limitFloor($this->siteAccount, $meter);
        if ($answers->limit < $floor) {
            return SetupRefusal::limitBelowFloor($this->amount($floor), $this->symbol);
        }

        if ($fund === null) {
            $instructions[] = Ix::openFund($this->program, $account, $mint, $answers->index);
            $plan[] = 'open fund '.$answers->index;
        }
        if ($answers->deposit > 0) {
            $instructions[] = Ix::deposit($this->program, $at['holding'], $account, $at['fund'], $mint, $answers->deposit, $this->decimals);
            $plan[] = 'deposit '.$this->amount($answers->deposit).' '.$this->symbol;
        }

        $key = (string) $answers->key;
        $expiry = $now + $answers->expirySeconds();
        if ($meter === null) {
            $instructions[] = Ix::openMeter($this->program, $this->site, $account, $at['fund'], $key, $answers->limit, $expiry);
            $plan[] = 'open a meter for this browser';
        } else {
            $instructions[] = Ix::renewMeter($this->program, $this->site, $account, $at['fund'], $key, $answers->limit, $expiry);
            $plan[] = 'renew the meter for this browser';
        }
        $plan[count($plan) - 1] .= ' with a limit of '.$this->amount($answers->limit).' '.$this->symbol;

        return new Composition($instructions, $plan, $at['fund'], $at['meter']);
    }

    private function amount(int $base): string
    {
        return Units::fromBaseUnits($base, $this->decimals);
    }
}
