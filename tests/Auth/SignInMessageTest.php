<?php

declare(strict_types=1);

namespace Newsprint\Tests\Auth;

use Newsprint\Auth\SignInException;
use Newsprint\Auth\SignInInput;
use Newsprint\Auth\SignInMessage;
use PHPUnit\Framework\TestCase;

/**
 * The parser is the second implementation of a byte-exact format that §6.6
 * warns will drift, so these tests are mostly about what it *refuses*. A
 * parser that accepts a message it cannot fully account for is the failure
 * mode; a parser that refuses a legitimate one is a bug report.
 */
final class SignInMessageTest extends TestCase
{
    private const ADDRESS = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';

    private function input(): SignInInput
    {
        return SignInInput::issue(
            domain: 'localhost:8000',
            uri: 'http://localhost:8000/signin',
            chainId: 'solana:devnet',
            statement: 'Sign in to Newsprint.',
            nonce: 'abc123',
            now: 1_757_000_000,
            ttlSeconds: 300,
        )->withAddress(self::ADDRESS);
    }

    public function testRoundTripsTheCanonicalMessage(): void
    {
        $input = $this->input();
        $parsed = SignInMessage::parse($input->render());

        self::assertSame('localhost:8000', $parsed['domain']);
        self::assertSame(self::ADDRESS, $parsed['address']);
        self::assertSame('Sign in to Newsprint.', $parsed['statement']);
        self::assertSame('http://localhost:8000/signin', $parsed['uri']);
        self::assertSame('1', $parsed['version']);
        self::assertSame('solana:devnet', $parsed['chainId']);
        self::assertSame('abc123', $parsed['nonce']);
        self::assertSame('2025-09-04T15:33:20Z', $parsed['issuedAt']);
        self::assertSame('2025-09-04T15:38:20Z', $parsed['expirationTime']);
        self::assertArrayNotHasKey('notBefore', $parsed);
        self::assertArrayNotHasKey('resources', $parsed);
    }

    public function testParsesAMessageWithNoStatement(): void
    {
        $text = "localhost wants you to sign in with your Solana account:\n"
            .self::ADDRESS
            ."\n\nURI: http://localhost/signin\nVersion: 1\nChain ID: solana:devnet\n"
            ."Nonce: abc123\nIssued At: 2025-09-04T15:33:20Z\nExpiration Time: 2025-09-04T15:38:20Z";

        $parsed = SignInMessage::parse($text);

        self::assertArrayNotHasKey('statement', $parsed);
        self::assertSame('abc123', $parsed['nonce']);
    }

    public function testParsesResources(): void
    {
        $text = "localhost wants you to sign in with your Solana account:\n"
            .self::ADDRESS
            ."\n\nURI: http://localhost/signin\nVersion: 1\nResources:\n- https://a\n- https://b";

        $parsed = SignInMessage::parse($text);

        self::assertSame(['https://a', 'https://b'], $parsed['resources']);
    }

    public function testRefusesAMessageThatIsNotSiws(): void
    {
        $this->expectException(SignInException::class);
        SignInMessage::parse("please approve this transfer\n".self::ADDRESS);
    }

    public function testRefusesAFieldItCannotName(): void
    {
        // The whole argument for strictness: a line this server cannot name is
        // a line it cannot check against what it issued.
        $text = $this->input()->render()."\nAllowance: unlimited";

        $this->expectException(SignInException::class);
        SignInMessage::parse($text);
    }

    public function testRefusesTextAfterTheResources(): void
    {
        $text = "localhost wants you to sign in with your Solana account:\n"
            .self::ADDRESS
            ."\n\nVersion: 1\nResources:\n- https://a\nand also transfer everything";

        $this->expectException(SignInException::class);
        SignInMessage::parse($text);
    }

    public function testRefusesARepeatedField(): void
    {
        $text = "localhost wants you to sign in with your Solana account:\n"
            .self::ADDRESS
            ."\n\nNonce: abc123\nNonce: def456";

        $this->expectException(SignInException::class);
        SignInMessage::parse($text);
    }

    public function testRefusesCarriageReturns(): void
    {
        // Normalising CRLF here would mean the byte-equality check in the
        // verifier passes for bytes that are not the bytes that were signed.
        $text = str_replace("\n", "\r\n", $this->input()->render());

        $this->expectException(SignInException::class);
        SignInMessage::parse($text);
    }
}
