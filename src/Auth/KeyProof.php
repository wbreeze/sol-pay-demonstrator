<?php

declare(strict_types=1);

namespace Newsprint\Auth;

use Newsprint\Chain\RpcException;
use Newsprint\Store\Store;
use SolPay\Core\Meter;
use SolPay\Core\Proof;

/**
 * SPEC §5.2: is the browser in front of this site the one a meter names?
 *
 * The library supplies `Proof::verifyKey`, which answers whether a signature
 * is valid and nothing else. This class is the rest, and the order is the
 * specification's, refusing at the first failure:
 *
 * 1. **The nonce** is one this server issued, not more than five minutes ago.
 *    It is forgotten here, at its first presentation, whether the proof then
 *    passes or not. A proof accepted twice can be replayed by whoever copies
 *    it, and each article the replayer reads is charged to the reader's fund.
 * 2. **The signature** is valid for the key the meter names, with the meter
 *    fetched from the chain.
 * 3. **The meter's `site`** is this site.
 *
 * **An expired meter binds**, decided 2026-10-01. The expiry is checked at
 * every charge, before anything is sent, and the program refuses `Expired`
 * besides, so a session on an expired meter can charge nothing. What it can
 * do is reach `/meter` and close the meter, which `SPEC.md` §5.5 promises "whether
 * the meter has expired or not". Refusing the proof made that promise
 * unkeepable for a reader whose session cookie ended with the browser.
 *
 * The signed bytes are `Newsprint key proof\n`, then the site's origin, then
 * the nonce's 32 raw bytes. The prefix and the origin are there so that a
 * proof is never also a valid message of any other kind, here or at another
 * site. The nonce is last and of fixed length, so the three cannot be read
 * apart two ways.
 */
final class KeyProof
{
    public const PREFIX = "Newsprint key proof\n";

    /** @var callable(string): ?Meter */
    private $readMeter;

    /**
     * @param callable(string): ?Meter $readMeter the meter at an address, as
     *                                            the chain holds it now, or null
     *                                            when there is no account; may
     *                                            throw {@see RpcException}
     */
    public function __construct(
        private readonly Store $store,
        private readonly string $site,
        callable $readMeter,
    ) {
        $this->readMeter = $readMeter;
    }

    /** The bytes the page signs. `$nonce` is raw, 32 bytes. */
    public static function bytes(string $origin, string $nonce): string
    {
        return self::PREFIX.$origin.$nonce;
    }

    /**
     * Check a proof, and say which meter it binds or why it binds none.
     *
     * @param string $nonce     as issued, hex
     * @param string $signature 64 raw bytes
     *
     * @throws RpcException the meter could not be read; the nonce is spent
     */
    public function check(string $meterAddress, string $nonce, string $origin, string $signature): Binding|ProofRefusal
    {
        // First, and before anything can fail for another reason: the nonce
        // is spent by being presented.
        if (preg_match('/^[0-9a-f]{64}$/', $nonce) !== 1 || !$this->store->consumeNonce($nonce)) {
            return ProofRefusal::Nonce;
        }

        $meter = ($this->readMeter)($meterAddress);
        if ($meter === null) {
            return ProofRefusal::NoMeter;
        }

        if (!Proof::verifyKey($meter->key, self::bytes($origin, (string) hex2bin($nonce)), $signature)) {
            return ProofRefusal::Signature;
        }

        if ($meter->site !== $this->site) {
            return ProofRefusal::AnotherSite;
        }

        return new Binding($meterAddress, $meter->fund, $meter->key);
    }
}
