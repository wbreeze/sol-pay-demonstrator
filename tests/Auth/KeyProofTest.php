<?php

declare(strict_types=1);

namespace Newsprint\Tests\Auth;

use Newsprint\Auth\Binding;
use Newsprint\Auth\KeyProof;
use Newsprint\Auth\ProofRefusal;
use Newsprint\Store\Database;
use Newsprint\Store\Store;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;
use SolPay\Core\Meter;

/**
 * SPEC §5.2's checks, with proofs signed in PHP through sodium: the same
 * Ed25519 the page signs with through WebCrypto, so a signature made here is
 * one the page could have made.
 *
 * Each refusal is tested with everything else right, so that the case shows
 * which check refused it, and the passing proof is the control.
 */
final class KeyProofTest extends TestCase
{
    private const SITE = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';
    private const OTHER_SITE = '3qZLoqdTunZJUANtRpaAnpFewAW4UVkgPn6VWzWTk4Mw';
    private const METER = 'Fgm6costwpmn4d1CTqdM5su8jBptdwnW134cNoFixgqs';
    private const FUND = 'BFT5EZLV7eWhwX4jjRP7JJDuJYoCQRmvmzuDUBbvSMqR';
    private const ORIGIN = 'http://localhost:8000';

    private int $now = 1_800_000_000;

    private Store $store;

    /** The browser's key pair, sodium's 64-byte secret and its public half. */
    private string $secret;
    private string $key;

    /** What the chain holds at METER, or null for no account. */
    private ?Meter $onChain = null;

    private int $reads = 0;

    protected function setUp(): void
    {
        $this->store = new Store(Database::open(':memory:'), fn (): int => $this->now);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->key = Base58::encode(sodium_crypto_sign_publickey($pair));
        $this->onChain = $this->meter();
    }

    public function testAProofByTheMetersKeyBindsTheMeterItsFundAndTheKey(): void
    {
        $nonce = $this->store->issueNonce(300);

        $bound = $this->proof()->check(self::METER, $nonce, self::ORIGIN, $this->sign($nonce));

        self::assertInstanceOf(Binding::class, $bound);
        self::assertSame([self::METER, self::FUND, $this->key], [$bound->meter, $bound->fund, $bound->key]);
    }

    public function testAReusedNonceIsRefused(): void
    {
        $nonce = $this->store->issueNonce(300);
        $signature = $this->sign($nonce);

        self::assertInstanceOf(Binding::class, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $signature));
        self::assertSame(ProofRefusal::Nonce, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $signature), 'the replay');
    }

    /** Forgotten at its first presentation, whether the proof then passes or not. */
    public function testANonceIsSpentByAProofThatFails(): void
    {
        $nonce = $this->store->issueNonce(300);

        self::assertSame(ProofRefusal::Signature, $this->proof()->check(self::METER, $nonce, self::ORIGIN, str_repeat("\0", 64)));
        self::assertSame(ProofRefusal::Nonce, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $this->sign($nonce)), 'a good signature after a bad one finds the nonce gone');
    }

    public function testANonceOlderThanFiveMinutesIsRefused(): void
    {
        $nonce = $this->store->issueNonce(300);
        $this->now += 301;

        self::assertSame(ProofRefusal::Nonce, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $this->sign($nonce)));
        self::assertSame(0, $this->reads, 'refused before the chain is asked');
    }

    public function testANonceThisServerNeverIssuedIsRefused(): void
    {
        $nonce = bin2hex(random_bytes(32));

        self::assertSame(ProofRefusal::Nonce, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $this->sign($nonce)));
    }

    /** SPEC §5.3: another device renewed the meter to its own key. */
    public function testAMeterNamingAnotherKeyRefusesThisKeysProof(): void
    {
        $other = sodium_crypto_sign_keypair();
        $this->onChain = $this->meter(key: Base58::encode(sodium_crypto_sign_publickey($other)));
        $nonce = $this->store->issueNonce(300);

        self::assertSame(ProofRefusal::Signature, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $this->sign($nonce)));
    }

    /** The origin is in the signed bytes, so a proof made for another site does not pass here. */
    public function testAProofSignedForAnotherOriginIsRefused(): void
    {
        $nonce = $this->store->issueNonce(300);
        $elsewhere = $this->sign($nonce, 'https://example.org');

        self::assertSame(ProofRefusal::Signature, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $elsewhere));
    }

    public function testAnotherSitesMeterIsRefused(): void
    {
        $this->onChain = $this->meter(site: self::OTHER_SITE);
        $nonce = $this->store->issueNonce(300);

        self::assertSame(ProofRefusal::AnotherSite, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $this->sign($nonce)));
    }

    /**
     * An expired meter binds (2026-10-01), so that its browser can reach
     * `/meter` and close it. A session on it can charge nothing: the charge
     * checks the expiry first.
     */
    public function testAnExpiredMeterStillBindsSoThatItCanBeClosed(): void
    {
        $this->onChain = $this->meter(expiry: $this->now - 1);
        $nonce = $this->store->issueNonce(300);

        self::assertInstanceOf(Binding::class, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $this->sign($nonce)));
    }

    public function testAClosedMeterIsRefused(): void
    {
        $this->onChain = null;
        $nonce = $this->store->issueNonce(300);

        self::assertSame(ProofRefusal::NoMeter, $this->proof()->check(self::METER, $nonce, self::ORIGIN, $this->sign($nonce)));
    }

    /** The bytes, pinned, because the page builds the same ones in `key.js`. */
    public function testTheSignedBytesArePrefixOriginAndTheRawNonce(): void
    {
        $nonce = str_repeat("\x01", 32);

        self::assertSame("Newsprint key proof\nhttp://localhost:8000".$nonce, KeyProof::bytes(self::ORIGIN, $nonce));
        self::assertStringContainsString("'Newsprint key proof\\n'", (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/key.js'), 'key.js signs the same prefix');
    }

    private function proof(): KeyProof
    {
        return new KeyProof($this->store, self::SITE, function (string $address): ?Meter {
            ++$this->reads;
            self::assertSame(self::METER, $address);

            return $this->onChain;
        });
    }

    private function sign(string $nonce, string $origin = self::ORIGIN): string
    {
        return sodium_crypto_sign_detached(KeyProof::bytes($origin, (string) hex2bin($nonce)), $this->secret);
    }

    private function meter(?string $key = null, string $site = self::SITE, ?int $expiry = null): Meter
    {
        return new Meter($site, self::FUND, $key ?? $this->key, $expiry ?? $this->now + 3_600, 500_000, 0, 0, 254);
    }
}
