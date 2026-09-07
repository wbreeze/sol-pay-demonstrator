<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Support\Config;
use SolPay\Core\Contract;
use SolPay\Core\DecodeException;
use SolPay\Core\Pda;
use SolPay\Core\TokenAccount;

/**
 * `find_contract` from the state diagram: derive the contract address from the
 * site and the wallet address, and read the account.
 *
 * Two accounts in one `getMultipleAccounts` — the contract and the reader's
 * token account — because the two questions the panel has to answer ("do you
 * have a contract" and "do you have anything to spend") arrive together and
 * §12.4 budgets about three RPC calls per metered view.
 *
 * The derivation is local. Nothing is looked up to find the contract: the
 * address is a function of the site and the payer, which is why a returning
 * reader with a fresh session finds their contract the moment they identify
 * and why the site never had to remember them.
 */
final class PayerReader
{
    public function __construct(
        private readonly Config $config,
        private readonly Rpc $rpc,
    ) {
    }

    /**
     * @throws RpcException    the endpoint did not answer
     * @throws DecodeException an account is not the shape this decoder expects
     */
    public function read(string $wallet, SiteState $state): PayerState
    {
        $addresses = $this->config->provisioned();

        ['address' => $contractAddress] = Pda::contractAddress(
            $state->address,
            $wallet,
            $this->config->program()->id,
        );

        $tokenAccount = AssociatedToken::address(
            $wallet,
            $addresses['mint'],
            $this->config->program()->tokenProgram,
        );

        $accounts = $this->rpc->multipleAccounts([$contractAddress, $tokenAccount]);

        return new PayerState(
            wallet: $wallet,
            contractAddress: $contractAddress,
            tokenAccount: $tokenAccount,
            contract: $accounts[0] === null ? null : Contract::decode($accounts[0]['data']),
            funds: $accounts[1] === null ? null : TokenAccount::decode($accounts[1]['data']),
            site: $state->site,
            decimals: $state->mintDecimals ?? (int) $this->config->siteParams()['decimals'],
        );
    }
}
