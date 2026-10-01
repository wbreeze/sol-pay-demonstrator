<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Chain\Keypair;
use Newsprint\Chain\MessageSigner;
use SolPay\Core\Base58;
use SolPay\Core\Ix;
use SolPay\Core\Program;
use SolPay\Core\Tx;

/**
 * SPEC §5.4: the reader leaves by closing the meter, and the browser key
 * signs the close.
 *
 * The key holds no SOL, so the site authority is the fee payer and signs
 * second, after the page has signed. The server composes the message, the
 * page signs exactly those bytes, and the server checks the page's signature
 * against the session's key before adding its own. A page cannot have the
 * authority sign anything but the message the server kept.
 *
 * **Why the key may sign bytes the server composed.** The program refuses
 * the browser key as signer of every instruction but `close_meter`
 * (`wasm-client/SPEC.md` §4.8). A server that handed the page another message
 * would get a signature the program does not honour.
 */
final class MeterClose
{
    private function __construct()
    {
    }

    /**
     * `close_meter` signed by the key, the authority paying the fee. The
     * meter's rent returns to `$reader`, the wallet the fund names.
     */
    public static function compose(
        Program $program,
        string $site,
        string $fund,
        string $reader,
        string $key,
        string $authority,
        string $blockhash,
    ): string {
        return Tx::compile([Ix::closeMeter($program, $key, $reader, $site, $fund)], $authority, $blockhash);
    }

    /**
     * The wire transaction, or null when `$keySignature` is not the key's
     * signature over `$message`.
     *
     * The signatures go in the order the message names its signers, which
     * puts the fee payer first. Any signer other than the key and the
     * authority is refused, since the server never composes one.
     *
     * @param string $keySignature 64 raw bytes, as WebCrypto returns them
     */
    public static function assemble(string $message, string $key, string $keySignature, Keypair $authority): ?string
    {
        $raw = Base58::decode($key);
        if (strlen($keySignature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }
        try {
            if (!sodium_crypto_sign_verify_detached($keySignature, $message, $raw)) {
                return null;
            }
        } catch (\SodiumException) {
            return null;
        }

        $required = ord($message[0]);
        $signatures = [];
        foreach (array_slice(MessageSigner::accountKeys($message), 0, $required) as $signer) {
            $signatures[] = match ($signer) {
                $authority->address => $authority->sign($message),
                $key => $keySignature,
                default => throw new \InvalidArgumentException("the message names a signer this site does not compose: {$signer}"),
            };
        }

        return Tx::wire($message, $signatures);
    }
}
