<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\AccountMeta;
use SolPay\Core\Base58;
use SolPay\Core\Instruction;

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
     * The instructions a compiled legacy message carries, each with its
     * accounts in the instruction's own order.
     *
     * For SPEC §9.2's panel after a close. The close is composed by one
     * request and sent by the next (§5.4), and the message is all that the
     * site keeps between the two. The builder's `Instruction` is gone by
     * then, so the panel shows what the key signed, read back out of the
     * kept bytes.
     *
     * A signer or writable flag is the message's: an account is a signer
     * when it sits among the required signatures, and writable by the
     * header's two readonly counts. Compilation merges flags across
     * instructions and forces the fee payer writable, so the flags here
     * equal the builder's wherever one instruction names an account once
     * and the fee payer is not among its accounts. That holds for the close.
     *
     * @return list<Instruction>
     */
    public static function instructions(string $message): array
    {
        if (strlen($message) < 4) {
            throw new \InvalidArgumentException('not a compiled message');
        }
        $required = ord($message[0]);
        $readonlySigned = ord($message[1]);
        $readonlyUnsigned = ord($message[2]);
        $keys = self::accountKeys($message);
        [$count, $offset] = self::shortVec($message, 3);
        // Past the keys, then past the 32-byte blockhash.
        $offset += 32 * $count + 32;

        $meta = static fn (int $i): AccountMeta => new AccountMeta(
            $keys[$i],
            $i < $required,
            $i < $required ? $i < $required - $readonlySigned : $i < $count - $readonlyUnsigned,
        );

        $instructions = [];
        [$n, $offset] = self::shortVec($message, $offset);
        for ($i = 0; $i < $n; $i++) {
            $program = $keys[ord($message[$offset++])];
            [$accountCount, $offset] = self::shortVec($message, $offset);
            $accounts = [];
            for ($j = 0; $j < $accountCount; $j++) {
                $accounts[] = $meta(ord($message[$offset++]));
            }
            [$length, $offset] = self::shortVec($message, $offset);
            $instructions[] = new Instruction($program, $accounts, substr($message, $offset, $length));
            $offset += $length;
        }

        return $instructions;
    }

    /**
     * compact-u16, read back. The encoder is `Tx`'s; this class is the only other
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
