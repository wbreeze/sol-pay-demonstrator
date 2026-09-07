<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\AccountMeta;
use SolPay\Core\Base58;
use SolPay\Core\Ids;
use SolPay\Core\Instruction;
use SolPay\Core\Pda;

/**
 * Metaplex Token Metadata, for one purpose: so a wallet says "0.5 DEMO"
 * instead of "0.5 Unknown".
 *
 * That is not cosmetic here. This site's argument is that a reader can see
 * what they are authorizing, and the approval screen is the one moment where
 * that claim is tested by someone who has not read the SPEC. A demo about
 * legibility whose own token has no name is arguing against itself.
 *
 * **This is a dependency the rest of the design does not have.** Nothing in
 * sol-pay knows about Metaplex, nothing on the metering path calls it, and the
 * site works without it — the mint is a perfectly good SPL Token mint either
 * way. It runs once, out of band, from `bin/name-the-mint`.
 *
 * Metaplex's own documentation now calls Token Metadata legacy and points new
 * work at Core. That is the right advice for an NFT project and the wrong
 * advice here: Core does not describe a fungible SPL Token mint, and Token
 * Metadata is what wallets actually read for one. Legacy-but-read is the
 * correct trade for a name on an approval screen.
 *
 * Layout checked against `mpl-token-metadata`'s own source on 2026-09-07 —
 * `instruction/mod.rs` for the discriminator and `instruction/metadata.rs`
 * for the account order.
 */
final class TokenMetadata
{
    /** `declare_id!` in the program's `lib.rs`. */
    public const PROGRAM_ID = 'metaqbxxUerdq28cj1RbAWkYQm3ybzjb6a8bt518x1s';

    /** `MetadataInstruction::CreateMetadataAccountV3` is the 34th variant. */
    private const CREATE_METADATA_ACCOUNT_V3 = 33;

    private const PREFIX = 'metadata';

    /**
     * The metadata account for a mint: seeds are the literal `metadata`, the
     * Token Metadata program id, and the mint. The program id appears *as a
     * seed* as well as being the deriving program, which looks redundant and
     * is not — it is what the program derives, so it is what must be passed.
     */
    public static function address(string $mint): string
    {
        [$address] = Pda::findProgramAddress([
            self::PREFIX,
            Address::bytes(self::PROGRAM_ID),
            Address::bytes($mint),
        ], Address::bytes(self::PROGRAM_ID));

        return Base58::encode($address);
    }

    /**
     * Name the mint.
     *
     * The three authorities are separate accounts in the instruction and are
     * the same key here — the faucet key, which §12.0's setup made the mint
     * authority. Message compilation folds the duplicates into one signer.
     *
     * `uri` is deliberately allowed to be empty. It would point at a JSON file
     * describing the token, and this site has nowhere to host one that §10.3
     * would approve of; a wallet shows the name and symbol regardless, and an
     * empty URI is more honest than a link to something that does not exist.
     */
    public static function createV3(
        string $mint,
        string $mintAuthority,
        string $payer,
        string $updateAuthority,
        string $name,
        string $symbol,
        string $uri = '',
        bool $isMutable = true,
    ): Instruction {
        $data = chr(self::CREATE_METADATA_ACCOUNT_V3)
            // DataV2
            .self::borshString($name)
            .self::borshString($symbol)
            .self::borshString($uri)
            .pack('v', 0)   // seller_fee_basis_points: u16. Not a royalty on a
                            // metering token; zero is the only honest value.
            ."\x00"         // creators: Option<Vec<Creator>> — None
            ."\x00"         // collection: Option<Collection> — None
            ."\x00"         // uses: Option<Uses> — None
            // CreateMetadataAccountArgsV3
            .($isMutable ? "\x01" : "\x00")
            ."\x00";        // collection_details: Option<CollectionDetails> — None

        return new Instruction(self::PROGRAM_ID, [
            new AccountMeta(self::address($mint), false, true),
            new AccountMeta($mint, false, false),
            new AccountMeta($mintAuthority, true, false),
            new AccountMeta($payer, true, true),
            new AccountMeta($updateAuthority, true, false),
            new AccountMeta(Ids::SYSTEM_PROGRAM_ID, false, false),
        ], $data);
    }

    /** Borsh strings are a u32 length, little-endian, then the bytes. */
    private static function borshString(string $value): string
    {
        return pack('V', strlen($value)).$value;
    }
}
