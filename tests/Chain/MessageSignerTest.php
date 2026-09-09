<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\Keypair;
use Newsprint\Chain\MessageSigner;
use Newsprint\Chain\SystemProgram;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;
use SolPay\Core\Ids;
use SolPay\Core\Tx;

/**
 * The submission path's one testable half: signatures are positional, so
 * getting their order wrong produces a transaction that is refused with
 * nothing to say which key was missing.
 */
final class MessageSignerTest extends TestCase
{
    private const BLOCKHASH = '11111111111111111111111111111112';

    public function testSignaturesFollowAccountKeyOrderAndVerify(): void
    {
        $payer = Keypair::generate();
        $created = Keypair::generate();
        $keys = [$payer->address => $payer, $created->address => $created];

        $message = Tx::compile([
            SystemProgram::createAccount($payer->address, $created->address, 1_000_000, 82, Ids::TOKEN_PROGRAM_ID),
        ], $payer->address, self::BLOCKHASH);

        $signatures = MessageSigner::signatures($message, $keys);
        self::assertCount(2, $signatures, 'two signers means two signatures');

        $accountKeys = MessageSigner::accountKeys($message);
        foreach ($signatures as $i => $signature) {
            self::assertTrue(
                sodium_crypto_sign_verify_detached($signature, $message, Base58::decode($accountKeys[$i])),
                "signature {$i} does not belong to account key {$i}",
            );
        }
    }

    /**
     * The rule php-client's README warns is invisible until a vector
     * disagrees with you: the fee payer is prepended, not sorted into place.
     * The other signer here sorts *before* it by raw bytes, which is the only
     * arrangement that can tell the two apart.
     *
     * **The arrangement is assigned, not drawn for.** This test used to
     * generate a payer and then up to 200 candidates, hoping one of them
     * landed below it -- which fails outright whenever the payer itself is
     * drawn near the bottom of the key space, because there is almost nothing
     * below it to find. Measured over 200,000 trials that is one run in 199
     * (0.50%), and every failure had a payer whose first byte was 0x00-0x05.
     * Four matrix legs per push made it roughly a 2% chance per push, which
     * is why CI went red on 2026-09-08 on the 8.4 leg alone while 8.2, 8.3
     * and 8.5 passed the same commit. A test that fails on the weather is
     * worse than no test: it spends a morning and teaches nothing.
     *
     * Two keys, roles assigned by their byte order, no loop and no draw that
     * can come up empty. Equal keys would be a 2^-256 event and are not
     * guarded against; the birthday bound on that is longer than the chain.
     */
    public function testFeePayerLeadsEvenWhenAnotherSignerSortsBefore(): void
    {
        $one = Keypair::generate();
        $two = Keypair::generate();

        // The greater of the two pays the fee, so the other necessarily sorts
        // before it -- which is the property under test, now by construction.
        [$lower, $payer] = strcmp($one->publicKeyBytes(), $two->publicKeyBytes()) < 0
            ? [$one, $two]
            : [$two, $one];

        self::assertLessThan(
            0,
            strcmp($lower->publicKeyBytes(), $payer->publicKeyBytes()),
            'the other signer must sort before the fee payer or this proves nothing',
        );

        $message = Tx::compile([
            SystemProgram::createAccount($payer->address, $lower->address, 1_000_000, 82, Ids::TOKEN_PROGRAM_ID),
        ], $payer->address, self::BLOCKHASH);

        self::assertSame($payer->address, MessageSigner::accountKeys($message)[0]);
    }

    public function testRefusesToSignWhenAKeyIsMissing(): void
    {
        $payer = Keypair::generate();
        $other = Keypair::generate();

        $message = Tx::compile([
            SystemProgram::createAccount($payer->address, $other->address, 1_000_000, 82, Ids::TOKEN_PROGRAM_ID),
        ], $payer->address, self::BLOCKHASH);

        $this->expectException(\RuntimeException::class);
        MessageSigner::signatures($message, [$payer->address => $payer]);
    }
}
