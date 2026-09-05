<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\AccountMeta;
use SolPay\Core\Instruction;

/**
 * The SPL Token instructions this site issues for itself.
 *
 * `php-client` builds the two instructions a site authority signs against the
 * metering program and deliberately nothing else, so creating a mint and
 * minting to a visitor are this repository's to write — SPEC §11's "anything
 * the library refused to ship … is the site's own and is marked as such".
 *
 * Layouts checked against the token program's own `pack()` on 2026-09-05
 * rather than recalled: a discriminant is a single byte at the front, and the
 * variant indexes are the declaration order of `TokenInstruction`.
 */
final class TokenProgram
{
    /**
     * A mint account is 82 bytes, and the arithmetic is worth writing down
     * because it is the one number here a cluster cannot be asked for:
     * `COption<Pubkey>` mint authority (4 + 32), supply (8), decimals (1),
     * is_initialized (1), `COption<Pubkey>` freeze authority (4 + 32) = 82.
     *
     * Note the *account* encoding of `COption` is a four-byte tag, while the
     * *instruction* encoding below is one byte. They are different formats in
     * the same program, which is exactly the sort of thing that gets conflated.
     */
    public const MINT_LEN = 82;

    /** A token account is 165 bytes, for `getMinimumBalanceForRentExemption`. */
    public const ACCOUNT_LEN = 165;

    private const INITIALIZE_MINT_2 = 20;
    private const MINT_TO = 7;

    /**
     * Initialize a mint in an account that already exists and is owned by the
     * token program. Variant 2 rather than the original: `InitializeMint`
     * takes the rent sysvar as an account, and this one does not.
     */
    public static function initializeMint2(
        string $mint,
        int $decimals,
        string $mintAuthority,
        ?string $freezeAuthority = null,
        string $tokenProgram = \SolPay\Core\Ids::TOKEN_PROGRAM_ID,
    ): Instruction {
        $data = chr(self::INITIALIZE_MINT_2)
            .chr($decimals)
            .Address::bytes($mintAuthority)
            // COption, instruction flavour: one tag byte, and the 32 bytes
            // only when it is Some.
            .($freezeAuthority === null ? "\x00" : "\x01".Address::bytes($freezeAuthority));

        return new Instruction($tokenProgram, [
            new AccountMeta($mint, false, true),
        ], $data);
    }

    /** Create tokens into an account. The demo's faucet, and nothing else (§4.3). */
    public static function mintTo(
        string $mint,
        string $destination,
        string $mintAuthority,
        int $amount,
        string $tokenProgram = \SolPay\Core\Ids::TOKEN_PROGRAM_ID,
    ): Instruction {
        return new Instruction($tokenProgram, [
            new AccountMeta($mint, false, true),
            new AccountMeta($destination, false, true),
            new AccountMeta($mintAuthority, true, false),
        ], chr(self::MINT_TO).pack('P', $amount));
    }
}
