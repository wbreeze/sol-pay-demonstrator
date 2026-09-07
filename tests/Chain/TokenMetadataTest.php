<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\TokenMetadata;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Ids;

/**
 * A hand-written encoding of somebody else's wire format, with no upstream to
 * notice drift — which is the exact situation `handoff/sol-pay/01` argues is
 * dangerous, so it gets the same treatment: a pinned literal, and the layout
 * asserted field by field rather than as one opaque blob.
 *
 * Wrong here costs a failed transaction and a real fee, and the failure is
 * silent in the way that matters: a malformed borsh string produces a
 * plausible instruction that the program rejects for a reason naming none of
 * this.
 *
 * Checked against `mpl-token-metadata` on 2026-09-07: `instruction/mod.rs`
 * puts `CreateMetadataAccountV3` at index 33, and `instruction/metadata.rs`
 * gives the six accounts in this order.
 */
final class TokenMetadataTest extends TestCase
{
    private const MINT = 'AKRs35WnmePSDLcPLyVtC5UPPBC8xjmVZ4EUJ3XwU9op';
    private const AUTHORITY = 'GCtMbPvNm3jvFb28jZPbqiGQ7aTAkomiiyjk2GqB1oyk';

    private function instruction(): \SolPay\Core\Instruction
    {
        return TokenMetadata::createV3(
            mint: self::MINT,
            mintAuthority: self::AUTHORITY,
            payer: self::AUTHORITY,
            updateAuthority: self::AUTHORITY,
            name: 'Newsprint DEMO',
            symbol: 'DEMO',
            uri: '',
        );
    }

    public function testTheDataMatchesTheRecordedEncoding(): void
    {
        // 21                  CreateMetadataAccountV3 (33)
        // 0e000000 "Newsprint DEMO"   borsh string: u32 length, then bytes
        // 04000000 "DEMO"
        // 00000000                    uri, empty
        // 0000                        seller_fee_basis_points: u16
        // 00 00 00                    creators / collection / uses: None
        // 01                          is_mutable
        // 00                          collection_details: None
        self::assertSame(
            '210e0000004e6577737072696e742044454d4f0400000044454d4f0000000000000000000100',
            bin2hex($this->instruction()->data),
        );
    }

    public function testTheDiscriminatorIsTheFirstByte(): void
    {
        self::assertSame(33, ord($this->instruction()->data[0]));
    }

    public function testStringsCarryAFourByteLittleEndianLength(): void
    {
        $data = $this->instruction()->data;
        // Skip the discriminator; the name's length prefix follows.
        self::assertSame(strlen('Newsprint DEMO'), unpack('V', substr($data, 1, 4))[1]);
        self::assertSame('Newsprint DEMO', substr($data, 5, 14));
    }

    public function testTheAccountsAreInTheOrderTheProgramExpects(): void
    {
        $accounts = $this->instruction()->accounts;

        self::assertSame(6, count($accounts));

        // 0 metadata, writable, not a signer — it does not exist yet.
        self::assertSame(TokenMetadata::address(self::MINT), $accounts[0]->pubkey);
        self::assertSame(false, $accounts[0]->isSigner);
        self::assertSame(true, $accounts[0]->isWritable);

        self::assertSame(self::MINT, $accounts[1]->pubkey);
        self::assertSame(false, $accounts[1]->isWritable);

        // 2 mint authority, 3 payer, 4 update authority. All three are the
        // faucet key here, and only the payer is written — it pays the rent.
        self::assertSame(true, $accounts[2]->isSigner);
        self::assertSame(false, $accounts[2]->isWritable);
        self::assertSame(true, $accounts[3]->isSigner);
        self::assertSame(true, $accounts[3]->isWritable);
        self::assertSame(true, $accounts[4]->isSigner);
        self::assertSame(false, $accounts[4]->isWritable);

        self::assertSame(Ids::SYSTEM_PROGRAM_ID, $accounts[5]->pubkey);
    }

    public function testTheMetadataAddressIsDerivedFromTheProgramAndTheMint(): void
    {
        // Pinned, because the seeds include the program id *as a seed* as well
        // as being the deriving program — the kind of redundancy that is easy
        // to "simplify" into a wrong address.
        self::assertSame('GBbsFm91J5qeyRvyD2d3uMiLfhcrKp76iDssm4K9962J', TokenMetadata::address(self::MINT));
    }
}
