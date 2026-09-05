<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\AccountMeta;
use SolPay\Core\Ids;
use SolPay\Core\Instruction;

/**
 * The two System Program instructions this site builds for itself.
 *
 * SPEC §11 puts everything sol-pay declines to ship in this repository, and
 * that includes the ordinary Solana plumbing setup needs: an account has to
 * exist and be owned by the token program before a mint can be initialized.
 * `php-client` has no view on any of this and should not — it builds the two
 * instructions a site authority signs, and this is a site.
 *
 * Instruction layout is System's own bincode enum: a u32 variant index, then
 * the fields. Transfer is variant 2, CreateAccount is variant 0.
 */
final class SystemProgram
{
    public static function transfer(string $from, string $to, int $lamports): Instruction
    {
        return new Instruction(Ids::SYSTEM_PROGRAM_ID, [
            new AccountMeta($from, true, true),
            new AccountMeta($to, false, true),
        ], pack('V', 2).pack('P', $lamports));
    }

    /**
     * Allocate and assign a new account. The new account signs — proof its
     * key was held rather than merely named.
     */
    public static function createAccount(
        string $from,
        string $newAccount,
        int $lamports,
        int $space,
        string $owner,
    ): Instruction {
        return new Instruction(Ids::SYSTEM_PROGRAM_ID, [
            new AccountMeta($from, true, true),
            new AccountMeta($newAccount, true, true),
        ], pack('V', 0).pack('P', $lamports).pack('P', $space).Address::bytes($owner));
    }
}
