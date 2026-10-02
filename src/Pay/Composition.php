<?php

declare(strict_types=1);

namespace Newsprint\Pay;

use SolPay\Core\Instruction;

/**
 * A composed setup: the instructions, in the order the program requires, and
 * a line saying what they do, for the wallet to show beside the transaction.
 */
final class Composition
{
    /**
     * @param list<Instruction> $instructions
     * @param list<string>      $plan         one phrase per step
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
        return 'Newsprint: '.implode(', then ', $this->plan).'.';
    }
}
