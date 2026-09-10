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
 * have a contract" and "do you have anything to spend") arrive together.
 *
 * The derivation is local. Nothing is looked up to find the contract: the
 * address is a function of the site and the payer, which is why a returning
 * reader with a fresh session finds their contract the moment they identify
 * and why the site never had to remember them.
 *
 * **And that is why these two accounts can travel with the site's three.**
 * `addresses()` takes a wallet and nothing else: the site address and the mint
 * both come from `config->provisioned()`, so neither of the two addresses this
 * reader wants depends on a value the *site account* returned. Nothing was
 * ever waiting for that read — only the signature said so. `read()` keeps the
 * sequential shape for callers with one question; {@see RequestRead} batches.
 *
 * A `SiteState` is still required to *decode*, for the `Site` struct and the
 * mint's decimals, and `$state->address` is the same string
 * `config->provisioned()['site']` returns — {@see SiteReader::decode()}
 * constructs it from exactly that.
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
        $addresses = $this->addresses($wallet);

        return $this->decode($wallet, $addresses, $this->rpc->multipleAccounts($addresses), $state);
    }

    /**
     * The contract and the reader's token account, derived from the wallet and
     * what setup recorded — no chain read stands behind either one.
     *
     * @return list<string> in the order {@see decode()} expects them back
     */
    public function addresses(string $wallet): array
    {
        $addresses = $this->config->provisioned();

        ['address' => $contractAddress] = Pda::contractAddress(
            $addresses['site'],
            $wallet,
            $this->config->program()->id,
        );

        return [
            $contractAddress,
            AssociatedToken::address(
                $wallet,
                $addresses['mint'],
                $this->config->program()->tokenProgram,
            ),
        ];
    }

    /**
     * @param list<string>                                                                   $addresses as `addresses()` returned them
     * @param list<array{data: string, owner: string, lamports: int, executable: bool}|null> $accounts  aligned with them
     *
     * @throws DecodeException an account is not the shape this decoder expects
     */
    public function decode(string $wallet, array $addresses, array $accounts, SiteState $state): PayerState
    {
        return new PayerState(
            wallet: $wallet,
            contractAddress: $addresses[0],
            tokenAccount: $addresses[1],
            contract: ($accounts[0] ?? null) === null ? null : Contract::decode($accounts[0]['data']),
            funds: ($accounts[1] ?? null) === null ? null : TokenAccount::decode($accounts[1]['data']),
            site: $state->site,
            decimals: $state->mintDecimals ?? (int) $this->config->siteParams()['decimals'],
        );
    }
}
