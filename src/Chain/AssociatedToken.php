<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\AccountMeta;
use SolPay\Core\Base58;
use SolPay\Core\Ids;
use SolPay\Core\Instruction;
use SolPay\Core\Pda;

/**
 * The associated token account: the canonical token account for a wallet and
 * a mint, and the one every wallet interface shows.
 *
 * SPEC §8.3 is worth reading beside this. The program constrains
 * `payer_token_account` only by owner and mint, so *any* token account the
 * payer owns for the site's mint is acceptable, and a reader could hold one
 * contract per token account. This site uses the ATA, because auxiliary token
 * accounts are second-class in nearly every wallet interface and asking an
 * ordinary reader to create and fund one is not a viable onboarding step.
 *
 * Derivation and instruction layout checked against the program's own
 * documentation on 2026-09-05.
 */
final class AssociatedToken
{
    public const PROGRAM_ID = 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL';

    private const CREATE_IDEMPOTENT = 1;

    /**
     * The address, derived rather than looked up. Seeds are the wallet, the
     * token program, and the mint, in that order — the token program is a seed
     * rather than an assumption, which is what lets Token-2022 accounts derive
     * separately for the same wallet and mint.
     *
     * Uses `php-client`'s `Pda`, which does its own field arithmetic for the
     * off-curve check: PHP's sodium exposes no ed25519 core API, and the
     * stricter substitute would silently derive wrong addresses on roughly
     * half of all inputs (`pda-spike/README.md`).
     */
    public static function address(
        string $owner,
        string $mint,
        string $tokenProgram = Ids::TOKEN_PROGRAM_ID,
    ): string {
        // `findProgramAddress` speaks raw bytes on both sides — seeds *and*
        // program id — and returns [address, bump] rather than the
        // ['address' => ...] shape `siteAddress` hands back. The two live in
        // the same class and differ, which is worth a second look before
        // copying either.
        [$address] = Pda::findProgramAddress([
            Address::bytes($owner),
            Address::bytes($tokenProgram),
            Address::bytes($mint),
        ], Address::bytes(self::PROGRAM_ID));

        return Base58::encode($address);
    }

    /**
     * Create it, or do nothing if it already exists.
     *
     * Idempotent rather than `Create`, because the non-idempotent variant
     * fails when the account is already there — which turns "run setup twice"
     * from harmless into a failed transaction, and would do the same to the
     * faucet the second time a visitor arrives.
     */
    public static function createIdempotent(
        string $funder,
        string $owner,
        string $mint,
        string $tokenProgram = Ids::TOKEN_PROGRAM_ID,
    ): Instruction {
        return new Instruction(self::PROGRAM_ID, [
            new AccountMeta($funder, true, true),
            new AccountMeta(self::address($owner, $mint, $tokenProgram), false, true),
            new AccountMeta($owner, false, false),
            new AccountMeta($mint, false, false),
            new AccountMeta(Ids::SYSTEM_PROGRAM_ID, false, false),
            new AccountMeta($tokenProgram, false, false),
        ], chr(self::CREATE_IDEMPOTENT));
    }
}
