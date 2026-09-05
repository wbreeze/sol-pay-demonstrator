<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\Keypair;
use Newsprint\Chain\SystemProgram;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;
use SolPay\Core\Ids;

/**
 * System's instruction data is a bincode enum: u32 variant index, then fields.
 * Hardcoded literals here for the same reason php-client's suites use them —
 * they name a local regression precisely.
 */
final class SystemProgramTest extends TestCase
{
    public function testTransferIsVariantTwoAndALittleEndianU64(): void
    {
        $from = Keypair::generate()->address;
        $to = Keypair::generate()->address;

        $ix = SystemProgram::transfer($from, $to, 890_880);

        self::assertSame(Ids::SYSTEM_PROGRAM_ID, $ix->programId);
        self::assertSame('0200000000980d0000000000', bin2hex($ix->data));

        self::assertCount(2, $ix->accounts);
        self::assertTrue($ix->accounts[0]->isSigner, 'the payer signs');
        self::assertTrue($ix->accounts[0]->isWritable);
        self::assertFalse($ix->accounts[1]->isSigner, 'the recipient does not');
        self::assertTrue($ix->accounts[1]->isWritable);
    }

    public function testCreateAccountCarriesLamportsSpaceAndOwner(): void
    {
        $from = Keypair::generate()->address;
        $new = Keypair::generate()->address;

        $ix = SystemProgram::createAccount($from, $new, 1_461_600, 82, Ids::TOKEN_PROGRAM_ID);

        self::assertSame('00000000', bin2hex(substr($ix->data, 0, 4)), 'variant 0');
        self::assertSame(1_461_600, unpack('P', substr($ix->data, 4, 8))[1]);
        self::assertSame(82, unpack('P', substr($ix->data, 12, 8))[1], 'a mint is 82 bytes');
        self::assertSame(Base58::decode(Ids::TOKEN_PROGRAM_ID), substr($ix->data, 20, 32));
        self::assertSame(52, strlen($ix->data));

        self::assertTrue($ix->accounts[1]->isSigner, 'the new account proves its key was held');
    }
}
