<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Auth\Binding;
use Newsprint\Support\Config;
use SolPay\Core\DecodeException;
use SolPay\Core\Fund;
use SolPay\Core\Meter;
use SolPay\Core\Pda;
use SolPay\Core\TokenAccount;

/**
 * The meter a session names and its fund's token account, in one
 * `getMultipleAccounts`.
 *
 * **Nothing is derived from what the site account says**, which is why these
 * two can travel with the site's three ({@see RequestRead}). The meter's
 * address comes from the session. The token account is the associated token
 * account of the fund the session recorded, in the mint `var/site.json`
 * recorded. Neither waits on a read.
 *
 * A `SiteState` is still needed to *decode*: the `Site` for the preflight
 * arithmetic, and the mint's decimals.
 */
final class MeterReader
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
    public function read(Binding $binding, SiteState $state): MeterState
    {
        $addresses = $this->addresses($binding);

        return $this->decode($binding, $addresses, $this->rpc->multipleAccounts($addresses), $state);
    }

    /**
     * The meter, then the fund's token account.
     *
     * @return list<string> in the order {@see decode()} expects them back
     */
    public function addresses(Binding $binding): array
    {
        return [
            $binding->meter,
            Pda::fundTokenAccount(
                $binding->fund,
                $this->config->provisioned()['mint'],
                $this->config->program()->tokenProgram,
            ),
            // The fund itself, for the inspector (SPEC §9.2): its reader, its
            // index and how many meters are open on it. A third address in
            // the same call, so it costs no round trip.
            $binding->fund,
        ];
    }

    /**
     * @param list<string>                                                                   $addresses as `addresses()` returned them
     * @param list<array{data: string, owner: string, lamports: int, executable: bool}|null> $accounts  aligned with them
     *
     * @throws DecodeException an account is not the shape this decoder expects
     */
    public function decode(Binding $binding, array $addresses, array $accounts, SiteState $state): MeterState
    {
        return new MeterState(
            meterAddress: $addresses[0],
            fund: $binding->fund,
            fundTokenAccount: $addresses[1],
            meter: ($accounts[0] ?? null) === null ? null : Meter::decode($accounts[0]['data']),
            funds: ($accounts[1] ?? null) === null ? null : TokenAccount::decode($accounts[1]['data']),
            site: $state->site,
            decimals: $state->mintDecimals ?? (int) $this->config->siteParams()['decimals'],
            fundAccount: ($accounts[2] ?? null) === null ? null : Fund::decode($accounts[2]['data']),
        );
    }
}
