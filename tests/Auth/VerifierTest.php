<?php

declare(strict_types=1);

namespace Newsprint\Tests\Auth;

use Newsprint\Auth\SignInException;
use Newsprint\Auth\SignInInput;
use Newsprint\Auth\Verifier;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;

/**
 * SPEC §5 step 3, checked against a real signature rather than a fixture: the
 * keypair is generated here and the message is signed with ext-sodium, so a
 * test that passes means the same code path a wallet exercises passed.
 *
 * Each refusal below is one of the two failures §5 names. A verifier that
 * checks the signature and not the fields accepts a valid signature over a
 * message about somebody else's domain. A verifier that checks the fields and
 * not the bytes accepts a message with the right fields and extra content
 * around them.
 */
final class VerifierTest extends TestCase
{
    private const NOW = 1_757_000_000;

    private string $secret;
    private string $address;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($keypair);
        $this->address = Base58::encode(sodium_crypto_sign_publickey($keypair));
    }

    private function issued(): SignInInput
    {
        return SignInInput::issue(
            domain: 'localhost:8000',
            uri: 'http://localhost:8000/signin',
            chainId: 'solana:devnet',
            statement: 'Sign in to Newsprint.',
            nonce: 'abc123',
            now: self::NOW,
            ttlSeconds: 300,
        );
    }

    private function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secret);
    }

    public function testAcceptsWhatAWalletWouldBuild(): void
    {
        $issued = $this->issued();
        $message = $issued->withAddress($this->address)->render();

        (new Verifier())->verify($issued, $this->address, $message, $this->sign($message), self::NOW + 5);

        // No exception is the assertion; this keeps the count honest without
        // asserting a tautology, which an analyser reads as a test that tests
        // nothing.
        $this->addToAssertionCount(1);
    }

    public function testRefusesASignatureFromAnotherKey(): void
    {
        $issued = $this->issued();
        $message = $issued->withAddress($this->address)->render();
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $this->expectException(SignInException::class);
        (new Verifier())->verify($issued, $this->address, $message, sodium_crypto_sign_detached($message, $other), self::NOW + 5);
    }

    public function testRefusesAValidSignatureOverAnotherSitesMessage(): void
    {
        // The phishing case, stated as a test: the signature is real and the
        // wallet is real, and the message is about somebody else's domain.
        $issued = $this->issued();
        $elsewhere = SignInInput::fromArray(
            ['domain' => 'newsprint.example'] + $issued->withAddress($this->address)->toArray()
        );
        $message = $elsewhere->render();

        $this->expectException(SignInException::class);
        (new Verifier())->verify($issued, $this->address, $message, $this->sign($message), self::NOW + 5);
    }

    public function testRefusesExtraContentAroundTheRightFields(): void
    {
        // Field equality alone would pass this; byte equality does not. This
        // is the check that stands in for the byte-exact comparison §5 gave up
        // when it dropped the `connect` + `signMessage` fallback.
        $issued = $this->issued();
        $message = $issued->withAddress($this->address)->render()."\n";

        $this->expectException(SignInException::class);
        (new Verifier())->verify($issued, $this->address, $message, $this->sign($message), self::NOW + 5);
    }

    public function testRefusesAMessageNamingADifferentWallet(): void
    {
        $issued = $this->issued();
        $someoneElse = Base58::encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $message = $issued->withAddress($someoneElse)->render();

        $this->expectException(SignInException::class);
        (new Verifier())->verify($issued, $this->address, $message, $this->sign($message), self::NOW + 5);
    }

    public function testRefusesAnExpiredChallenge(): void
    {
        // A verifier that skips the expiry accepts a replay forever.
        $issued = $this->issued();
        $message = $issued->withAddress($this->address)->render();

        $this->expectException(SignInException::class);
        (new Verifier())->verify($issued, $this->address, $message, $this->sign($message), self::NOW + 301);
    }

    public function testRefusesAFieldThisSiteDidNotAskFor(): void
    {
        $issued = $this->issued();
        $withExtra = SignInInput::fromArray(
            $issued->withAddress($this->address)->toArray() + ['requestId' => 'anything']
        );
        $message = $withExtra->render();

        $this->expectException(SignInException::class);
        (new Verifier())->verify($issued, $this->address, $message, $this->sign($message), self::NOW + 5);
    }
}
