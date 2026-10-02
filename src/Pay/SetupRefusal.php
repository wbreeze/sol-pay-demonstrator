<?php

declare(strict_types=1);

namespace Newsprint\Pay;

/**
 * Why the server would not compose a setup (SPEC §6.3, "What the server
 * refuses to compose"). Checked before the wallet sees anything, because a
 * transaction that fails the wallet's own simulation is a worse screen than a
 * refusal. Each is a sentence for the wallet to show and for the panel.
 */
final class SetupRefusal
{
    private function __construct(public readonly string $message)
    {
    }

    public static function depositShort(string $deposit, string $holding, string $symbol): self
    {
        return new self("A deposit of {$deposit} {$symbol} from a wallet holding {$holding} {$symbol}: the wallet cannot cover it.");
    }

    public static function limitBelowFloor(string $floor, string $symbol): self
    {
        return new self("The smallest limit this meter can take is {$floor} {$symbol}.");
    }

    public static function anotherWallet(): self
    {
        return new self('This fund belongs to another wallet. Scan with the wallet that opened it.');
    }

    public static function noFund(int $index): self
    {
        return new self("This wallet has no fund {$index} at this site's mint, so there is nothing to add to.");
    }
}
