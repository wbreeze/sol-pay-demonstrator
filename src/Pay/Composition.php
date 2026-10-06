<?php

declare(strict_types=1);

namespace Newsprint\Pay;

use SolPay\Core\Instruction;

/**
 * A composed setup: the instructions, in the order the program requires, and
 * a line saying what they do, for the wallet to show beside the transaction.
 *
 * **The line is written for a wallet that shows thirty characters of it.**
 * Solflare on a phone cut "Newsprint: open fund 0, then de…" there on
 * 2026-10-06 (SPEC §13.4), under a label that already said Newsprint. So the
 * phrases come in order of what the reader most needs, not in the order of
 * the instructions, and the first phrase has to stand alone. The limit leads
 * because the wallet shows it nowhere else: the deposit and the rent appear
 * as amounts on the wallet's own screen.
 */
final class Composition
{
    /**
     * @param list<Instruction> $instructions
     * @param list<string>      $plan         one phrase per step, the most needed first
     */
    public function __construct(
        public readonly array $instructions,
        public readonly array $plan,
        public readonly string $fund,
        public readonly string $meter,
    ) {
    }

    public function message(): string
    {
        return implode('; ', $this->plan).'.';
    }
}
