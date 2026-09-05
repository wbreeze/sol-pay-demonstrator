<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Base58;

/** Base58 in, 32 raw bytes out, with the length actually checked. */
final class Address
{
    public static function bytes(string $address): string
    {
        $raw = Base58::decode($address);
        if (strlen($raw) !== 32) {
            throw new \InvalidArgumentException("not a 32-byte address: {$address}");
        }

        return $raw;
    }
}
