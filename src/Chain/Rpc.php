<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Program;

/**
 * JSON-RPC over curl. SPEC §12.4: the public devnet endpoint, **called from
 * the server**.
 *
 * That last part is a privacy decision as much as an operational one (§10.3).
 * An RPC endpoint sees the IP address of whoever calls it and the accounts
 * they ask about; if the browser called it, the provider would learn a
 * reader's IP alongside their wallet address. Calling from here, it sees one
 * server asking about many accounts and learns nothing about any individual
 * reader.
 *
 * No Guzzle. The whole surface is five methods over one POST, and
 * `php-client` having zero runtime dependencies would be a strange thing to
 * celebrate and then undo in the application that demonstrates it.
 */
final class Rpc
{
    public function __construct(
        private readonly string $url,
        private readonly Program $program,
        private readonly string $commitment = 'confirmed',
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    /** @return array{blockhash: string, lastValidBlockHeight: int} */
    public function latestBlockhash(): array
    {
        $value = $this->call('getLatestBlockhash', [['commitment' => $this->commitment]])['value'];

        return [
            'blockhash' => (string) $value['blockhash'],
            'lastValidBlockHeight' => (int) $value['lastValidBlockHeight'],
        ];
    }

    public function balance(string $address): int
    {
        return (int) $this->call('getBalance', [$address, ['commitment' => $this->commitment]])['value'];
    }

    /**
     * The read at the top of every metered request: `Site`, `Contract` and the
     * payer's token account in one round trip (§12.4).
     *
     * @param list<string> $addresses
     *
     * @return list<array{data: string, owner: string, lamports: int}|null> aligned with $addresses
     */
    public function multipleAccounts(array $addresses): array
    {
        $value = $this->call('getMultipleAccounts', [
            array_values($addresses),
            ['commitment' => $this->commitment, 'encoding' => 'base64'],
        ])['value'];

        $out = [];
        foreach ($value as $account) {
            if ($account === null) {
                $out[] = null;
                continue;
            }
            $out[] = [
                'data' => (string) base64_decode((string) $account['data'][0], true),
                'owner' => (string) $account['owner'],
                'lamports' => (int) $account['lamports'],
            ];
        }

        return $out;
    }

    public function minimumBalanceForRentExemption(int $bytes): int
    {
        return (int) $this->call('getMinimumBalanceForRentExemption', [$bytes]);
    }

    /** Devnet only, and rate limited at the endpoint. SPEC §12.0's first run leans on it. */
    public function requestAirdrop(string $address, int $lamports): string
    {
        return (string) $this->call('requestAirdrop', [$address, $lamports]);
    }

    /** @param string $wire raw transaction bytes from `Tx::wire` */
    public function sendTransaction(string $wire): string
    {
        return (string) $this->call('sendTransaction', [
            base64_encode($wire),
            ['encoding' => 'base64', 'preflightCommitment' => $this->commitment],
        ]);
    }

    /**
     * @param list<string> $signatures
     *
     * @return list<array{confirmationStatus: ?string, err: mixed}|null>
     */
    public function signatureStatuses(array $signatures): array
    {
        $value = $this->call('getSignatureStatuses', [
            array_values($signatures),
            ['searchTransactionHistory' => false],
        ])['value'];

        return array_map(
            static fn ($s) => $s === null ? null : [
                'confirmationStatus' => isset($s['confirmationStatus']) ? (string) $s['confirmationStatus'] : null,
                'err' => $s['err'] ?? null,
            ],
            $value,
        );
    }

    /**
     * @param list<mixed> $params
     *
     * @throws RpcException on transport failure, a non-200, or a JSON-RPC error
     */
    public function call(string $method, array $params = []): mixed
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

        $curl = curl_init($this->url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $transport = curl_error($curl);
        // No curl_close: the handle has been an object since PHP 8.0, freed
        // when it goes out of scope, and calling it is deprecated as of 8.5 —
        // which `php-conformance.yml`'s upper version would have caught in
        // sol-pay and this repository has no equivalent of yet.

        if ($raw === false) {
            throw new RpcException("{$method}: {$transport}");
        }
        if ($status !== 200) {
            // A rate-limited public endpoint answers 429 here, and SPEC §12.4
            // says that failure looks like the site being broken. Name it.
            throw new RpcException("{$method}: HTTP {$status}");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RpcException("{$method}: response was not JSON");
        }
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $failure = Failure::fromRpcError($decoded['error'], $this->program);

            throw new RpcException("{$method}: {$failure->message}", $failure);
        }

        return $decoded['result'] ?? null;
    }
}
