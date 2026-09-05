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
 * rather than three, and the metering path will add the contract and the
 * payer's token account to the same read.
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

        $addresses = $this->config->provisioned();
        $accounts = $this->rpc->multipleAccounts([
            $addresses['site'],
            $addresses['treasury'],
            $addresses['mint'],
        ]);

        if ($accounts[0] === null) {
            // Recorded locally, absent on chain. A different cluster, or a
            // `var/site.json` carried over from somewhere else.
            return null;
        }

        return new SiteState(
            $addresses['site'],
            Site::decode($accounts[0]['data']),
            $accounts[1] === null ? null : TokenAccount::decode($accounts[1]['data']),
            $accounts[2] === null ? null : Mint::decimals($accounts[2]['data']),
        );
    }
}
