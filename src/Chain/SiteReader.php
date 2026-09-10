<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Support\Config;
use SolPay\Core\DecodeException;
use SolPay\Core\Mint;
use SolPay\Core\Site;
use SolPay\Core\TokenAccount;

/**
 * One round trip, three accounts (SPEC §12.4): the `Site`, the treasury token
 * account, and the mint. `getMultipleAccounts` exists so this is one call
 * rather than three, and the metering path adds the contract and the payer's
 * token account to the same read.
 *
 * **That last clause was an intention for a long time and is now true.** The
 * addresses and the decoding are named separately from the call — see
 * `addresses()` and `decode()` — so {@see RequestRead} can put these three in
 * front of the payer's two and spend one round trip on all five. This class
 * stays the only thing that knows what a `Site` account looks like; what it
 * gave up is the assumption that it is the only reader on the request.
 *
 * Called from the server, never from the browser — §10.3's reasoning, which is
 * a privacy decision as much as an operational one: an endpoint the browser
 * called would learn a reader's IP beside a wallet address.
 */
final class SiteReader
{
    public function __construct(
        private readonly Config $config,
        private readonly Rpc $rpc,
    ) {
    }

    /**
     * @throws RpcException   the endpoint did not answer
     * @throws DecodeException an account is not the shape this decoder expects
     */
    public function read(): ?SiteState
    {
        if (!$this->config->isProvisioned()) {
            return null;
        }

        return $this->decode($this->rpc->multipleAccounts($this->addresses()));
    }

    /**
     * The three accounts, in the order {@see decode()} expects them back.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        $addresses = $this->config->provisioned();

        return [$addresses['site'], $addresses['treasury'], $addresses['mint']];
    }

    /**
     * @param list<array{data: string, owner: string, lamports: int, executable: bool}|null> $accounts
     *
     * @throws DecodeException an account is not the shape this decoder expects
     */
    public function decode(array $accounts): ?SiteState
    {
        if (($accounts[0] ?? null) === null) {
            // Recorded locally, absent on chain. A different cluster, or a
            // `var/site.json` carried over from somewhere else.
            return null;
        }

        return new SiteState(
            $this->config->provisioned()['site'],
            Site::decode($accounts[0]['data']),
            ($accounts[1] ?? null) === null ? null : TokenAccount::decode($accounts[1]['data']),
            ($accounts[2] ?? null) === null ? null : Mint::decimals($accounts[2]['data']),
        );
    }
}
