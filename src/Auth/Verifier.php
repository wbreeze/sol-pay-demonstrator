<?php

declare(strict_types=1);

namespace Newsprint\Auth;

use Newsprint\Chain\Address;

/**
 * SPEC §5 step 3, in one place: verify the signature over the bytes the wallet
 * returned, then check that those bytes say what this server asked for.
 *
 * Both halves are load-bearing and they fail differently. A verifier that
 * checks the signature and not the fields accepts a valid signature over a
 * message about somebody else's domain — which is what a phishing site
 * collects. A verifier that checks the fields and not the signature accepts
 * anything at all.
 *
 * Three checks that §5 calls out by name, kept together here so it is visible
 * that none of them is missing:
 *
 * - **the nonce**, consumed by the caller exactly once (see `Store`);
 * - **`expirationTime`**, because a verifier that skips the expiry accepts a
 *   replay forever;
 * - **every issued field**, compared to the input this server composed rather
 *   than to whatever the message happens to contain.
 *
 * And one check §5 does not require, which is cheap and closes the gap the
 * dropped `connect` + `signMessage` fallback would have closed for free: after
 * parsing, the parsed fields are re-rendered through
 * {@see SignInInput::render()} and the result must equal the signed bytes
 * exactly. Field equality alone permits a message with the right fields and
 * extra bytes around them; byte equality does not. Against a wallet that
 * builds the canonical text this is the byte-for-byte check §5 gave up, and
 * against one that does not it is a clear refusal rather than a silent
 * acceptance.
 */
final class Verifier
{
    /**
     * Sixty seconds of tolerance for `issuedAt` being slightly ahead of this
     * server's clock. The wallet stamps it, the wallet's clock is not this
     * one, and refusing a sign-in over a two-second skew is a bug report
     * nobody can reproduce.
     */
    private const CLOCK_SKEW_S = 60;

    /**
     * @param string $address       base58, as the wallet's `account.address` reported it
     * @param string $signedMessage the raw bytes the wallet returned
     * @param string $signature     the raw 64-byte signature
     *
     * @throws SignInException when the sign-in is not to be granted
     */
    public function verify(
        SignInInput $issued,
        string $address,
        string $signedMessage,
        string $signature,
        int $now,
    ): void {
        try {
            $publicKey = Address::bytes($address);
        } catch (\InvalidArgumentException $e) {
            throw new SignInException('the wallet returned an address that is not a Solana address', 'malformed');
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new SignInException('the wallet returned a signature of the wrong length', 'malformed');
        }

        // ext-sodium has had the signature API since 7.2. It is only the
        // ed25519 *core point* API that PHP lacks, which is a separate finding
        // and the reason `Pda` does its own field arithmetic.
        if (!sodium_crypto_sign_verify_detached($signature, $signedMessage, $publicKey)) {
            throw new SignInException('the signature does not verify under that wallet address', 'signature');
        }

        $parsed = SignInMessage::parse($signedMessage);

        if (($parsed['address'] ?? null) !== $address) {
            // The signature verified under the account the wallet named, but
            // the message says a different address. One of the two is a lie
            // and there is no way to tell which.
            throw new SignInException('the signed message names a different wallet than the one that signed it', 'address');
        }

        $expected = $issued->withAddress($address);
        $this->same('domain', $expected->domain, $parsed['domain'] ?? null);
        $this->same('URI', $expected->uri, $parsed['uri'] ?? null);
        $this->same('version', $expected->version, $parsed['version'] ?? null);
        $this->same('chain', $expected->chainId, $parsed['chainId'] ?? null);
        $this->same('nonce', $expected->nonce, $parsed['nonce'] ?? null);
        $this->same('issue time', $expected->issuedAt, $parsed['issuedAt'] ?? null);
        $this->same('expiry', $expected->expirationTime, $parsed['expirationTime'] ?? null);
        $this->same('statement', $expected->statement, $parsed['statement'] ?? null);

        foreach (['notBefore', 'requestId', 'resources'] as $unasked) {
            if (isset($parsed[$unasked])) {
                throw new SignInException('the signed message carries a field this site did not ask for', 'fields');
            }
        }

        if (!hash_equals($expected->render(), $signedMessage)) {
            throw new SignInException('the signed message is not the message this site asked for', 'bytes');
        }

        // Both directions. Past the expiry is a replay or a very slow reader;
        // issued in the future by more than the skew allowance means the two
        // clocks disagree enough that the expiry means nothing either.
        $expiresAt = self::timestamp($expected->expirationTime, 'expiry');
        if ($now >= $expiresAt) {
            throw new SignInException('this sign-in request expired; ask for another', 'expired');
        }

        $issuedAt = self::timestamp($expected->issuedAt, 'issue time');
        if ($issuedAt > $now + self::CLOCK_SKEW_S) {
            throw new SignInException('this sign-in request is stamped in the future', 'clock');
        }
    }

    private function same(string $what, ?string $expected, ?string $found): void
    {
        if ($expected === $found) {
            return;
        }

        throw new SignInException("the signed message has a different {$what} than this site asked for", 'fields');
    }

    private static function timestamp(string $value, string $what): int
    {
        $parsed = strtotime($value);
        if ($parsed === false) {
            throw new SignInException("the signed message has an unreadable {$what}", 'malformed');
        }

        return $parsed;
    }
}
