<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Base58;

/**
 * An ed25519 keypair, and the only place in this repository that signs
 * anything (SPEC §4.4).
 *
 * `sodium_crypto_sign_detached` — ext-sodium's *signature* API, present since
 * 7.2. The ed25519 core-point API PHP does not expose is a different function
 * and a different problem; `php-client/pda-spike/README.md` has that finding,
 * and it is why sol-pay derives PDAs with hand-written field arithmetic
 * instead. Confirmed absent again on PHP 8.4.21 while writing this.
 *
 * **The on-disk format is the Solana CLI's**: a JSON array of 64 bytes, seed
 * then public key. Same file `solana-keygen` writes, so a key made here can be
 * inspected with `solana address -k`, and one made there can be dropped in.
 * That interop is worth more than a format of our own.
 */
final class Keypair
{
    private function __construct(
        private string $secret,
        public readonly string $address,
    ) {
    }

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        sodium_memzero($pair);

        return self::fromSecret($secret);
    }

    /** @param string $secret 64 raw bytes: seed ‖ public key */
    public static function fromSecret(string $secret): self
    {
        if (strlen($secret) !== 64) {
            throw new \InvalidArgumentException('secret key must be 64 bytes, got '.strlen($secret));
        }

        return new self($secret, Base58::encode(substr($secret, 32, 32)));
    }

    public static function load(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("no keypair at {$path}");
        }
        $bytes = json_decode($raw, true);
        if (!is_array($bytes) || count($bytes) !== 64) {
            throw new \RuntimeException("{$path} is not a 64-byte Solana keypair file");
        }

        return self::fromSecret(pack('C*', ...array_map('intval', $bytes)));
    }

    public static function loadOrCreate(string $path): self
    {
        if (is_file($path)) {
            return self::load($path);
        }
        $keypair = self::generate();
        $keypair->save($path);

        return $keypair;
    }

    public function save(string $path): void
    {
        // Written 0600 before anything goes in it: a key that is briefly
        // world-readable is a key that was world-readable.
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("cannot write keypair to {$path}");
        }
        @chmod($path, 0o600);
        fwrite($handle, json_encode(array_values(unpack('C*', $this->secret))));
        fclose($handle);
    }

    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secret);
    }

    public function publicKeyBytes(): string
    {
        return substr($this->secret, 32, 32);
    }

    public function __destruct()
    {
        sodium_memzero($this->secret);
    }
}
