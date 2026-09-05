<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Base58;

/**
 * Signatures in account-key order, read out of the compiled message.
 *
 * `Tx::wire` takes signatures positionally — signature *n* belongs to account
 * key *n* — and `Tx::compile` does not hand back the key list. The order could
 * be re-derived here from the instructions, and that is the wrong answer: the
 * partition sort is the part of compilation easiest to get subtly wrong (keys
 * ascend by raw pubkey bytes, not by the order instructions named them), and a
 * second implementation of it in this repository would be a second thing to
 * keep in step with sol-pay's vectors. The message is the source of truth, so
 * this reads the keys back out of it.
 *
 * Its own right to exist as a separate class: it is the one piece of the
 * submission path that can be tested without a validator.
 */
final class MessageSigner
{
    /**
     * @param array<string, Keypair> $signers by address
     *
     * @return list<string> exactly as many as the message's header requires
     */
    public static function signatures(string $message, array $signers): array
    {
        if (strlen($message) < 4) {
            throw new \InvalidArgumentException('not a compiled message');
        }
        $required = ord($message[0]);
        [$count, $offset] = self::shortVec($message, 3);
        if ($count < $required) {
            throw new \InvalidArgumentException('message names fewer keys than it requires signatures');
        }

        $out = [];
        for ($i = 0; $i < $required; $i++) {
            $address = Base58::encode(substr($message, $offset + 32 * $i, 32));
            if (!isset($signers[$address])) {
                throw new \RuntimeException("no key held for required signer {$address}");
            }
            $out[] = $signers[$address]->sign($message);
        }

        return $out;
    }

    /** The account keys in message order, first to last. @return list<string> */
    public static function accountKeys(string $message): array
    {
        [$count, $offset] = self::shortVec($message, 3);
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $keys[] = Base58::encode(substr($message, $offset + 32 * $i, 32));
        }

        return $keys;
    }

    /**
     * compact-u16, read back. The encoder is `Tx`'s; this is the only other
     * place in the demonstrator that needs to understand the framing.
     *
     * @return array{int, int} the value, and the offset just past it
     */
    private static function shortVec(string $bytes, int $offset): array
    {
        $value = 0;
        $shift = 0;
        while (true) {
            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return [$value, $offset];
            }
            $shift += 7;
        }
    }
}
