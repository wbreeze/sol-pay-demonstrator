<?php

declare(strict_types=1);

namespace Newsprint\Support;

use Newsprint\Chain\PayerState;
use Newsprint\Chain\SiteState;
use SolPay\Core\Units;

/**
 * The panel of SPEC §9, assembled.
 *
 * Its job is claim 7 in §2 — *every number on the screen came from an account,
 * not from the server's memory* — so the ordering principle here is provenance:
 * what this copy is configured with, then what the chain actually says, and a
 * line whenever the two disagree.
 *
 * Amounts appear in base units **and** through `Units::fromBaseUnits`, at the
 * decimals the mint itself reports. §6.2 of the library's spec warns that the
 * six-decimal scaling error — turning 50 into 50,000,000 — is invisible until
 * you see the two numbers side by side, so the panel always shows both.
 */
final class Inspector
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return list<array{heading: string, rows: list<array{0: string, 1: string}>, note?: string}>
     */
    public function sections(?SiteState $state = null, ?string $error = null, ?PayerState $payer = null): array
    {
        $program = $this->config->program();
        $params = $this->config->siteParams();

        $sections = [[
            'heading' => 'Deployment',
            'rows' => [
                [Alias::for(Alias::PROGRAM, $program->id), $program->id],
                [Alias::for(Alias::TOKEN_PROGRAM, $program->tokenProgram), $program->tokenProgram],
                ['cluster', 'devnet'],
                ['endpoint', $this->config->rpcUrl()],
            ],
            'note' => 'The endpoint is called by this server, never by your browser. An RPC provider that saw '
                .'your browser would learn your IP address beside a wallet address; seeing this server, it '
                .'learns that one server asked about some accounts.',
        ]];

        if ($error !== null) {
            $sections[] = [
                'heading' => 'This site, on chain',
                'rows' => [['read failed', $error]],
                'note' => 'The page is still served. Nothing on this site depends on the chain being reachable '
                    .'until a charge has to be made.',
            ];

            return $sections;
        }

        if ($state === null) {
            $sections[] = [
                'heading' => 'This site, on chain',
                'rows' => [['status', 'not provisioned']],
                'note' => 'First-run setup has not created the mint, the treasury or the site account yet (§12.0).',
            ];
            $sections[] = $this->configuredPrices($params);

            return $sections;
        }

        $decimals = $state->mintDecimals ?? (int) $params['decimals'];
        $site = $state->site;
        $symbol = (string) $params['symbol'];

        $amount = static fn (int $base): string => sprintf(
            '%s %s  (%d base units)',
            Units::fromBaseUnits($base, $decimals),
            $symbol,
            $base,
        );

        $sections[] = [
            'heading' => 'Site account, decoded',
            'rows' => [
                [Alias::for(Alias::SITE, $state->address), $state->address],
                ['authority', $site->authority],
                [Alias::for(Alias::MINT, $site->mint), $site->mint],
                [Alias::for(Alias::TREASURY, $site->treasury), $site->treasury],
                ['page price', $amount($site->pagePrice)],
                ['collection threshold', $amount($site->collectionThreshold).sprintf('  — %d views', intdiv($site->collectionThreshold, max(1, $site->pagePrice)))],
                ['minimum limit', $amount($site->minLimit).sprintf('  — %d views', intdiv($site->minLimit, max(1, $site->pagePrice)))],
                ['bump', (string) $site->bump],
                ['mint decimals', $state->mintDecimals === null ? 'unread' : (string) $state->mintDecimals],
            ],
            'note' => 'Read from the account on this request, in the field order of wasm-client/SPEC.md §6.2. '
                .'Amounts appear twice on purpose: a six-decimal scaling error is invisible until the two forms sit side by side.',
        ];

        if ($state->treasury !== null) {
            $sections[] = [
                'heading' => 'Treasury',
                'rows' => [
                    [Alias::for(Alias::TREASURY, $site->treasury), $site->treasury],
                    ['balance', $amount($state->treasury->amount)],
                    ['owner', $state->treasury->owner],
                    ['delegate', $state->treasury->delegate ?? 'none'],
                ],
                'note' => 'What readers have paid, so far, on this deployment.',
            ];
        }

        // Provenance made visible: config/site.php decided what setup wrote,
        // and the chain has been the authority ever since. If they disagree,
        // the number on every other page came from the chain, and this says so.
        $drift = [];
        foreach ([
            'page price' => [(int) $params['page_price'], $site->pagePrice],
            'collection threshold' => [(int) $params['collection_threshold'], $site->collectionThreshold],
            'minimum limit' => [(int) $params['min_limit'], $site->minLimit],
        ] as $label => [$configured, $onChain]) {
            if ($configured !== $onChain) {
                $drift[] = [$label, sprintf('config says %d, the chain says %d', $configured, $onChain)];
            }
        }

        if ($drift !== []) {
            $sections[] = [
                'heading' => 'Configuration drift',
                'rows' => $drift,
                'note' => 'The chain is what charges. Editing config/site.php after setup changes nothing on '
                    .'chain — initialize_site runs once.',
            ];
        }

        if ($payer !== null) {
            $sections[] = $this->reader($payer, $amount);
        }

        return $sections;
    }

    /**
     * You, on chain.
     *
     * **The delegate is the row that matters and the one nothing else shows.**
     * `approve_checked` names this site's contract PDA as the delegate on the
     * reader's token account and sets how much it may draw; `revoke` clears
     * both. That is the whole of what authorizing gave away and the whole of
     * what closing takes back — and a wallet will happily show a balance
     * without ever mentioning it.
     *
     * So it is here, read from the account on every request, in both unit
     * forms, beside the address a reader can paste into any explorer. Claim 6
     * in §2 is only checkable if the thing it is about is visible somewhere.
     *
     * @param callable(int): string $amount both unit forms, at the mint's own decimals
     *
     * @return array{heading: string, rows: list<array{0: string, 1: string}>, note?: string}
     */
    private function reader(PayerState $payer, callable $amount): array
    {
        $rows = [
            [Alias::for(Alias::PAYER, $payer->wallet), $payer->wallet],
            [Alias::for(Alias::PAYER_TOKEN_ACCOUNT, $payer->tokenAccount), $payer->tokenAccount],
        ];

        if ($payer->funds === null) {
            $rows[] = ['token account', 'does not exist yet — the faucet creates it'];
        } else {
            $rows[] = ['balance', $amount($payer->funds->amount)];
            $rows[] = [
                'delegate',
                $payer->funds->delegate === null
                    ? 'none — nothing may draw from this account'
                    : Alias::for(Alias::CONTRACT, $payer->funds->delegate).'  '.$payer->funds->delegate,
            ];
            $rows[] = ['approved', $amount($payer->funds->delegatedAmount)];
        }

        if ($payer->contract === null) {
            $rows[] = ['contract', 'none — the address is derived, the account is not there'];
        } else {
            $rows[] = [Alias::for(Alias::CONTRACT, $payer->contractAddress), $payer->contractAddress];
            $rows[] = ['limit', $amount($payer->contract->limit)];
            $rows[] = ['used', $amount($payer->contract->used)];
            $rows[] = ['paid', $amount($payer->contract->paid)];
            $rows[] = ['unpaid', $amount($payer->contract->unpaid())];
        }

        return [
            'heading' => 'You, on chain',
            'rows' => $rows,
            'note' => 'The delegate line is what authorizing gave this site and what closing takes back. '
                .'It lives on your token account, not in the contract, and most wallets never show it — so it '
                .'is here, read back from the account on every request, and the address beside it is the one to '
                .'paste into an explorer if you would rather not take this page\'s word for it.',
        ];
    }

    /** @param array<string, int|string> $params @return array{heading: string, rows: list<array{0: string, 1: string}>} */
    private function configuredPrices(array $params): array
    {
        $decimals = (int) $params['decimals'];

        return [
            'heading' => 'Site parameters, configured',
            'rows' => [
                ['page price', sprintf('%s %s  (%d base units)', Units::fromBaseUnits((int) $params['page_price'], $decimals), (string) $params['symbol'], (int) $params['page_price'])],
                ['collection threshold', sprintf('%s %s  (%d views)', Units::fromBaseUnits((int) $params['collection_threshold'], $decimals), (string) $params['symbol'], intdiv((int) $params['collection_threshold'], (int) $params['page_price']))],
                ['minimum limit', sprintf('%s %s  (%d views)', Units::fromBaseUnits((int) $params['min_limit'], $decimals), (string) $params['symbol'], intdiv((int) $params['min_limit'], (int) $params['page_price']))],
                ['mint decimals', (string) $decimals],
            ],
        ];
    }
}
