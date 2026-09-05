<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\Keypair;
use Newsprint\Chain\TokenProgram;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;

/**
 * Layouts taken from the token program's own `pack()` on 2026-09-05, not from
 * memory, and pinned here as literals so a later edit that "tidies" one is a
 * failing assertion rather than a transaction devnet rejects.
 */
final class TokenProgramTest extends TestCase
{
    public function testInitializeMintTwoIsVariantTwentyWithAOneByteOptionTag(): void
    {
        $mint = Keypair::generate()->address;
        $authority = Keypair::generate()->address;

        $ix = TokenProgram::initializeMint2($mint, 6, $authority);

        self::assertSame(20, ord($ix->data[0]), 'InitializeMint2, not InitializeMint — no rent sysvar account');
        self::assertSame(6, ord($ix->data[1]));
        self::assertSame(Base58::decode($authority), substr($ix->data, 2, 32));
        self::assertSame("\x00", substr($ix->data, 34), 'no freeze authority: a one-byte None');
        self::assertSame(35, strlen($ix->data));

        self::assertCount(1, $ix->accounts, 'the mint, and nothing else');
        self::assertTrue($ix->accounts[0]->isWritable);
        self::assertFalse($ix->accounts[0]->isSigner);
    }

    public function testAFreezeAuthorityIsATagAndThirtyTwoBytes(): void
    {
        $freeze = Keypair::generate()->address;
        $ix = TokenProgram::initializeMint2(
            Keypair::generate()->address,
            6,
            Keypair::generate()->address,
            $freeze,
        );

        self::assertSame("\x01", substr($ix->data, 34, 1));
        self::assertSame(Base58::decode($freeze), substr($ix->data, 35, 32));
        self::assertSame(67, strlen($ix->data));
    }

    public function testMintToIsVariantSevenAndALittleEndianU64(): void
    {
        $ix = TokenProgram::mintTo(
            Keypair::generate()->address,
            Keypair::generate()->address,
            $authority = Keypair::generate()->address,
            600_000, // §4.3's 0.60 DEMO
        );

        // 600000 = 0x000927c0, so little-endian is c0 27 09 — and the whole
        // instruction is nine bytes, not eight. This literal was wrong on the
        // first pass, which is the argument for having it.
        self::assertSame('07c027090000000000', bin2hex($ix->data));
        self::assertSame(9, strlen($ix->data));
        self::assertSame($authority, $ix->accounts[2]->pubkey);
        self::assertTrue($ix->accounts[2]->isSigner, 'the mint authority signs');
    }

    public function testAMintIsEightyTwoBytes(): void
    {
        // COption authority (4+32) + supply (8) + decimals (1) + initialized
        // (1) + COption freeze (4+32). The account encoding's COption is four
        // bytes, unlike the instruction's one.
        self::assertSame(82, TokenProgram::MINT_LEN);
        self::assertSame(165, TokenProgram::ACCOUNT_LEN);
    }
}
