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
     * The other signer here is chosen to sort *before* it by raw bytes, which
     * is the only arrangement that can tell the two apart.
     */
    public function testFeePayerLeadsEvenWhenAnotherSignerSortsBefore(): void
    {
        $payer = Keypair::generate();
        $lower = null;
        for ($i = 0; $i < 200 && $lower === null; $i++) {
            $candidate = Keypair::generate();
            if (strcmp($candidate->publicKeyBytes(), $payer->publicKeyBytes()) < 0) {
                $lower = $candidate;
            }
        }
        self::assertNotNull($lower, 'could not draw a key below the fee payer');

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
