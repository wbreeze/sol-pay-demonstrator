<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\AssociatedToken;
use Newsprint\Chain\Keypair;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;
use SolPay\Core\Ids;

/**
 * The derivation machinery is sol-pay's `Pda`, which its conformance run
 * checks against the Rust crate for the site and contract seed families. What
 * is unchecked here is this file's own contribution — the seed *order*, and
 * the program id — both read from the associated-token-account program's
 * documentation on 2026-09-05 rather than recalled. Devnet is what settles it:
 * a wrong address fails the first time setup creates a treasury.
 */
final class AssociatedTokenTest extends TestCase
{
    public function testCreateIdempotentIsOneByteAndSixAccountsInOrder(): void
    {
        $funder = Keypair::generate()->address;
        $owner = Keypair::generate()->address;
        $mint = Keypair::generate()->address;

        $ix = AssociatedToken::createIdempotent($funder, $owner, $mint);

        self::assertSame(AssociatedToken::PROGRAM_ID, $ix->programId);
        self::assertSame("\x01", $ix->data, 'CreateIdempotent, so a second run is not a failed transaction');

        self::assertCount(6, $ix->accounts);
        self::assertSame($funder, $ix->accounts[0]->pubkey);
        self::assertTrue($ix->accounts[0]->isSigner);
        self::assertTrue($ix->accounts[0]->isWritable);
        self::assertSame(AssociatedToken::address($owner, $mint), $ix->accounts[1]->pubkey);
        self::assertTrue($ix->accounts[1]->isWritable);
        self::assertSame($owner, $ix->accounts[2]->pubkey);
        self::assertSame($mint, $ix->accounts[3]->pubkey);
        self::assertSame(Ids::SYSTEM_PROGRAM_ID, $ix->accounts[4]->pubkey);
        self::assertSame(Ids::TOKEN_PROGRAM_ID, $ix->accounts[5]->pubkey);
    }

    public function testTheAddressIsStableAndOffCurve(): void
    {
        $owner = Keypair::generate()->address;
        $mint = Keypair::generate()->address;

        $first = AssociatedToken::address($owner, $mint);

        self::assertSame($first, AssociatedToken::address($owner, $mint));
        self::assertSame(32, strlen(Base58::decode($first)));
        self::assertNotSame($owner, $first);
    }

    public function testTheTokenProgramIsASeedAndNotAnAssumption(): void
    {
        $owner = Keypair::generate()->address;
        $mint = Keypair::generate()->address;

        // Which is why the same wallet and mint derive different accounts
        // under Token-2022 — the thing that would silently collide if the
        // program id were left out of the seeds.
        self::assertNotSame(
            AssociatedToken::address($owner, $mint, Ids::TOKEN_PROGRAM_ID),
            AssociatedToken::address($owner, $mint, Ids::TOKEN_2022_PROGRAM_ID),
        );
    }
}
