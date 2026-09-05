<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\Keypair;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;

final class KeypairTest extends TestCase
{
    public function testAddressIsTheBase58PublicKey(): void
    {
        $keypair = Keypair::generate();

        self::assertSame(32, strlen(Base58::decode($keypair->address)));
        self::assertSame($keypair->publicKeyBytes(), Base58::decode($keypair->address));
    }

    public function testSignsWhatSodiumVerifies(): void
    {
        $keypair = Keypair::generate();
        $message = random_bytes(96);
        $signature = $keypair->sign($message);

        self::assertSame(64, strlen($signature));
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, $message, $keypair->publicKeyBytes()));
        self::assertFalse(sodium_crypto_sign_verify_detached($signature, $message.'x', $keypair->publicKeyBytes()));
    }

    /** The on-disk format is the Solana CLI's, so a round trip has to be exact. */
    public function testRoundTripsThroughASolanaCliKeypairFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kp').'.json';
        $original = Keypair::generate();
        $original->save($path);

        $bytes = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($bytes);
        self::assertCount(64, $bytes, 'seed ‖ public key, as solana-keygen writes it');

        $loaded = Keypair::load($path);
        self::assertSame($original->address, $loaded->address);

        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        unlink($path);
    }

    public function testRefusesAFileThatIsNotAKeypair(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kp').'.json';
        file_put_contents($path, json_encode([1, 2, 3]));

        $this->expectException(\RuntimeException::class);
        try {
            Keypair::load($path);
        } finally {
            unlink($path);
        }
    }
}
