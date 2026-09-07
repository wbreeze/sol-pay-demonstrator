<?php

declare(strict_types=1);

namespace Newsprint\Auth;

/**
 * The `SolanaSignInInput` this server issues, and the canonical message text a
 * wallet builds from it (SPEC §5 step 1).
 *
 * The distinction §5 makes is the whole reason this class exists: **this is a
 * set of fields, not a message.** `signIn` "shifts the responsibility of
 * message construction from apps to the wallet", so the bytes that come back
 * are the wallet's construction and not this server's. {@see render()} is
 * therefore not what gets signed — it is what *should* have been signed, kept
 * here so the verifier can hold the returned bytes against it.
 *
 * `render()` mirrors `createSignInMessageText` in
 * `@solana/wallet-standard-util` (`packages/core/util/src/signIn.ts`), which is
 * the function wallets use: header line, address, an optional statement after
 * a blank line, then the labelled fields after another blank line. Checked
 * against that source 2026-09-07. If it drifts, {@see Verifier} fails closed —
 * it rejects rather than accepts — which is the right direction for a
 * mismatch.
 *
 * **`address` is deliberately absent from what this server issues**, and that
 * is a departure from §5's field list worth stating. A first-time reader has
 * not connected a wallet, so the server does not know the address to put in
 * the input; requiring one would mean `connect` first and then `signIn`, which
 * is the two-gesture flow §5 dropped the fallback to avoid and which §6.3
 * names as an Android hazard. The wallet fills the address in, and
 * {@see Verifier} binds it: the address in the signed message must equal the
 * account the wallet returned, and the signature must verify under it.
 */
final class SignInInput
{
    /**
     * @param list<string> $resources
     */
    private function __construct(
        public readonly string $domain,
        public readonly ?string $address,
        public readonly ?string $statement,
        public readonly string $uri,
        public readonly string $version,
        public readonly string $chainId,
        public readonly string $nonce,
        public readonly string $issuedAt,
        public readonly string $expirationTime,
        public readonly ?string $notBefore = null,
        public readonly ?string $requestId = null,
        public readonly array $resources = [],
    ) {
    }

    /**
     * Compose one, for a nonce the store has already issued.
     *
     * Times are RFC 3339 with a `Z` offset and second precision, because the
     * wallet echoes these strings into the message verbatim and the verifier
     * compares them as strings. A format that varies between issue and
     * comparison would fail every sign-in for a reason nobody would guess.
     */
    public static function issue(
        string $domain,
        string $uri,
        string $chainId,
        string $statement,
        string $nonce,
        int $now,
        int $ttlSeconds,
    ): self {
        return new self(
            domain: $domain,
            address: null,
            statement: $statement === '' ? null : $statement,
            uri: $uri,
            version: '1',
            chainId: $chainId,
            nonce: $nonce,
            issuedAt: self::stamp($now),
            expirationTime: self::stamp($now + $ttlSeconds),
        );
    }

    /** @param array<string, mixed> $fields */
    public static function fromArray(array $fields): self
    {
        $resources = [];
        if (isset($fields['resources']) && is_array($fields['resources'])) {
            foreach ($fields['resources'] as $resource) {
                $resources[] = (string) $resource;
            }
        }

        return new self(
            domain: (string) ($fields['domain'] ?? ''),
            address: isset($fields['address']) ? (string) $fields['address'] : null,
            statement: isset($fields['statement']) ? (string) $fields['statement'] : null,
            uri: (string) ($fields['uri'] ?? ''),
            version: (string) ($fields['version'] ?? ''),
            chainId: (string) ($fields['chainId'] ?? ''),
            nonce: (string) ($fields['nonce'] ?? ''),
            issuedAt: (string) ($fields['issuedAt'] ?? ''),
            expirationTime: (string) ($fields['expirationTime'] ?? ''),
            notBefore: isset($fields['notBefore']) ? (string) $fields['notBefore'] : null,
            requestId: isset($fields['requestId']) ? (string) $fields['requestId'] : null,
            resources: $resources,
        );
    }

    /**
     * What crosses to the browser, and what is stored against the nonce.
     *
     * Null fields are omitted rather than sent as null: the wallet includes a
     * line for every field present in the input, so a present-but-null
     * `notBefore` risks a `Not Before: ` line in the message that this server
     * never asked for.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'domain' => $this->domain,
            'uri' => $this->uri,
            'version' => $this->version,
            'chainId' => $this->chainId,
            'nonce' => $this->nonce,
            'issuedAt' => $this->issuedAt,
            'expirationTime' => $this->expirationTime,
        ];
        if ($this->address !== null) {
            $out['address'] = $this->address;
        }
        if ($this->statement !== null) {
            $out['statement'] = $this->statement;
        }
        if ($this->notBefore !== null) {
            $out['notBefore'] = $this->notBefore;
        }
        if ($this->requestId !== null) {
            $out['requestId'] = $this->requestId;
        }
        if ($this->resources !== []) {
            $out['resources'] = $this->resources;
        }

        return $out;
    }

    /** This input with the address the wallet returned filled in. */
    public function withAddress(string $address): self
    {
        return new self(
            domain: $this->domain,
            address: $address,
            statement: $this->statement,
            uri: $this->uri,
            version: $this->version,
            chainId: $this->chainId,
            nonce: $this->nonce,
            issuedAt: $this->issuedAt,
            expirationTime: $this->expirationTime,
            notBefore: $this->notBefore,
            requestId: $this->requestId,
            resources: $this->resources,
        );
    }

    /**
     * The canonical message text for these fields.
     *
     * Byte-for-byte `createSignInMessageText`. The address is required here —
     * a message has one — so this throws rather than rendering a blank line
     * when called on an input the wallet has not yet completed.
     */
    public function render(): string
    {
        if ($this->address === null || $this->address === '') {
            throw new \LogicException('render() needs the address the wallet returned; call withAddress() first');
        }

        $text = $this->domain.' wants you to sign in with your Solana account:'."\n".$this->address;

        if ($this->statement !== null) {
            $text .= "\n\n".$this->statement;
        }

        $fields = [];
        $fields[] = 'URI: '.$this->uri;
        $fields[] = 'Version: '.$this->version;
        $fields[] = 'Chain ID: '.$this->chainId;
        $fields[] = 'Nonce: '.$this->nonce;
        $fields[] = 'Issued At: '.$this->issuedAt;
        $fields[] = 'Expiration Time: '.$this->expirationTime;
        if ($this->notBefore !== null) {
            $fields[] = 'Not Before: '.$this->notBefore;
        }
        if ($this->requestId !== null) {
            $fields[] = 'Request ID: '.$this->requestId;
        }
        if ($this->resources !== []) {
            $fields[] = 'Resources:';
            foreach ($this->resources as $resource) {
                $fields[] = '- '.$resource;
            }
        }

        return $text."\n\n".implode("\n", $fields);
    }

    private static function stamp(int $epochSeconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $epochSeconds);
    }
}
