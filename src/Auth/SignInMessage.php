<?php

declare(strict_types=1);

namespace Newsprint\Auth;

/**
 * The inverse of {@see SignInInput::render()}: the fields a signed message
 * actually carries.
 *
 * This exists because of the property SPEC §5 takes on deliberately when it
 * requires `signIn` with no fallback — **the wallet constructs the message, so
 * the server must read what was signed rather than compare it to something it
 * composed.** §6.6 warns that two implementations of one byte-exact format
 * disagree eventually, and recommends a `siws` crate; §12.1 chose PHP, which
 * has none, so this is the second implementation and it is written to fail
 * closed.
 *
 * Strictness is the whole design. Anything this parser does not recognise is a
 * rejection, not a field it ignores: a message with a label the server never
 * issued, a second address line, or trailing text after the last field is a
 * message about something other than what was asked for. {@see Verifier} then
 * re-renders what was parsed and requires it to equal the signed bytes, so
 * "parsed successfully" and "is exactly this message" are two separate checks
 * and both have to pass.
 */
final class SignInMessage
{
    private const HEADER_SUFFIX = ' wants you to sign in with your Solana account:';

    /** Labels this server understands, in the order the canonical text emits them. */
    private const LABELS = [
        'URI: ' => 'uri',
        'Version: ' => 'version',
        'Chain ID: ' => 'chainId',
        'Nonce: ' => 'nonce',
        'Issued At: ' => 'issuedAt',
        'Expiration Time: ' => 'expirationTime',
        'Not Before: ' => 'notBefore',
        'Request ID: ' => 'requestId',
    ];

    /**
     * @return array<string, mixed> the fields, shaped like `SolanaSignInInput`
     *
     * @throws SignInException on anything that is not a well-formed SIWS message
     */
    public static function parse(string $text): array
    {
        // CRLF is not a variant to accept. The canonical text uses "\n", and
        // normalising here would mean the re-render check in Verifier passes
        // for bytes that are not the bytes that were signed.
        $lines = explode("\n", $text);

        $header = array_shift($lines);
        if ($header === null || !str_ends_with($header, self::HEADER_SUFFIX)) {
            throw new SignInException('the signed message is not a Sign In With Solana message', 'malformed');
        }
        $domain = substr($header, 0, -strlen(self::HEADER_SUFFIX));
        if ($domain === '') {
            throw new SignInException('the signed message names no domain', 'malformed');
        }

        $address = array_shift($lines);
        if ($address === null || $address === '') {
            throw new SignInException('the signed message names no address', 'malformed');
        }

        $fields = ['domain' => $domain, 'address' => $address];

        // A statement is present when the next two lines are a blank and
        // something that is not a label. The canonical text puts exactly one
        // blank line before each of the two optional blocks, so a run of two
        // blanks is not a statement — it is a malformed message.
        if ($lines !== [] && $lines[0] === '') {
            array_shift($lines);
            $statement = array_shift($lines);
            if ($statement === null) {
                throw new SignInException('the signed message ends after a blank line', 'malformed');
            }
            if ($statement !== '' && !self::isLabelled($statement)) {
                $fields['statement'] = $statement;
                // The blank line that separates the statement from the fields.
                $separator = array_shift($lines);
                if ($separator !== null && $separator !== '') {
                    throw new SignInException('the signed message runs the statement into its fields', 'malformed');
                }
            } else {
                // No statement: what followed the blank line was the first
                // field, so put it back.
                array_unshift($lines, $statement);
            }
        }

        $resources = [];
        $inResources = false;
        foreach ($lines as $line) {
            if ($inResources) {
                if (!str_starts_with($line, '- ')) {
                    throw new SignInException('the signed message has content after its resources', 'malformed');
                }
                $resources[] = substr($line, 2);

                continue;
            }

            if ($line === 'Resources:') {
                $inResources = true;

                continue;
            }

            $matched = false;
            foreach (self::LABELS as $label => $key) {
                if (str_starts_with($line, $label)) {
                    if (isset($fields[$key])) {
                        throw new SignInException('the signed message repeats a field', 'malformed');
                    }
                    $fields[$key] = substr($line, strlen($label));
                    $matched = true;

                    break;
                }
            }

            if (!$matched) {
                // Deliberately not skipped. A line this server cannot name is
                // a line it cannot check, and a message it cannot fully check
                // is one it will not accept.
                throw new SignInException('the signed message carries a field this site did not ask for', 'malformed');
            }
        }

        if ($resources !== []) {
            $fields['resources'] = $resources;
        }

        return $fields;
    }

    private static function isLabelled(string $line): bool
    {
        if ($line === 'Resources:') {
            return true;
        }
        foreach (array_keys(self::LABELS) as $label) {
            if (str_starts_with($line, $label)) {
                return true;
            }
        }

        return false;
    }
}
