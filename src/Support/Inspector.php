<?php

declare(strict_types=1);

namespace Newsprint\Support;

use Newsprint\Chain\PayerState;
use Newsprint\Chain\SiteState;
use Newsprint\Metering\MeterResult;
use SolPay\Core\Preflight;
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
     * A row is a label and a value, and — where §9 asks for it — a third cell
     * naming the on-chain check the value mirrors. The third cell is what
     * makes the preflight section evidence rather than a readout: a number
     * with no claim beside it cannot be wrong about anything.
     *
     * A section may also carry a `link` (§9 asks for the explorer beside a
     * signature) and an `event` — a signature whose decoded event the panel
     * fetches when it is opened rather than on the request that made it. See
     * {@see lastTransaction()} for why that read is deferred.
     *
     * @return list<array{heading: string, rows: list<array{0: string, 1: string, 2?: string}>, note?: string, link?: array{href: string, text: string}, event?: string}>
     */
    public function sections(?SiteState $state = null, ?string $error = null, ?PayerState $payer = null, ?MeterResult $result = null): array
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
            $sections[] = $this->preflight($state, $payer, $amount);
        }

        if ($result !== null && $result->signature !== null) {
            $sections[] = $this->lastTransaction($result, $state, $payer);
        }

        return $sections;
    }

    /**
     * The last transaction (SPEC §9).
     *
     * **"Last" is bounded by this request, and that is §10.4 rather than
     * laziness.** This site keeps no record of a reader's metering calls —
     * §10.4 enumerates its stores and a per-wallet list of signatures is
     * exactly the reading history the design exists not to hold. So the only
     * transaction the panel can show is the one this request produced, and on
     * a page that metered nothing the section is simply absent. A demo that
     * showed "your last five" would be a nicer panel and a broken promise.
     *
     * **The instructions are the builders' output, not a reading of the
     * transaction.** §9 wants them shown "as the builders produced them",
     * because the claim under test is that `SolPay\Core\Ix`'s output drops
     * straight into a message without adjustment. {@see MeterResult} carries
     * them out of {@see \Newsprint\Metering\Meter} for that reason. Reading
     * them back off the chain would answer a different and easier question.
     *
     * **Which is why the browser's transactions show less here, and say so.**
     * `approve_and_open`, `renew_contract` and `close_and_revoke` are compiled
     * in the reader's browser by the wasm client and this server never holds
     * those instruction objects. Their signature and their decoded event are
     * shown; their bytes are not, with a line saying who built them. The
     * alternative — decoding the landed transaction so all four look alike —
     * was declined 2026-09-08: it would put "as they landed" under a heading
     * that promises "as the builders produced them", and quietly answer a
     * question §9 did not ask.
     *
     * **The event is fetched when the panel opens, not now.** §12.4 budgets
     * about three RPC calls per metered view and `getTransaction` would be a
     * fourth, spent on every metered request whether or not anybody expands
     * the panel — which §9 says is collapsed by default. So the section
     * carries the signature and `assets/inspector.js` reads the event on
     * first open. The row below is what a reader sees until then.
     *
     * @return array{heading: string, rows: list<array{0: string, 1: string, 2?: string}>, note?: string, link?: array{href: string, text: string}, event?: string}
     */
    private function lastTransaction(MeterResult $result, ?SiteState $state, ?PayerState $payer): array
    {
        $signature = (string) $result->signature;

        $rows = [
            ['signature', $signature],
            ['outcome', $result->outcome->value.' — '.$result->detail],
            ['page views', (string) $result->pageViews, $result->settles ? 'this call settles' : 'accrues only'],
        ];

        if ($result->instructions === []) {
            $rows[] = [
                'instructions',
                'built in your browser by the wasm client, so this server never held them',
                'signature and event only',
            ];
        }

        $aliases = $this->aliasesFor($state, $payer);

        foreach ($result->instructions as $i => $instruction) {
            $n = $i + 1;
            $rows[] = [
                sprintf('ix %d · program', $n),
                $this->named($instruction->programId, $aliases),
            ];

            foreach ($instruction->accounts as $j => $account) {
                $flags = [];
                if ($account->isSigner) {
                    $flags[] = 'signer';
                }
                if ($account->isWritable) {
                    $flags[] = 'writable';
                }
                $rows[] = [
                    sprintf('ix %d · account %d', $n, $j + 1),
                    $this->named($account->pubkey, $aliases),
                    $flags === [] ? 'readonly' : implode(', ', $flags),
                ];
            }

            $data = bin2hex($instruction->data);
            $rows[] = [
                sprintf('ix %d · data', $n),
                strlen($data) > 16 ? substr($data, 0, 16).' '.substr($data, 16) : $data,
                sprintf('%d bytes: 8-byte discriminator, then borsh', strlen($instruction->data)),
            ];
        }

        return [
            'heading' => 'The last transaction',
            'rows' => $rows,
            'link' => [
                'href' => 'https://explorer.solana.com/tx/'.rawurlencode($signature).'?cluster=devnet',
                'text' => 'This transaction on the Solana explorer',
            ],
            'event' => $signature,
            'note' => 'This request\'s transaction, and only this one — §10.4 keeps no list of what you have '
                .'metered, so there is nothing here to look back through. The first eight data bytes are the '
                .'Anchor discriminator, sha256("global:meter_and_settle") truncated; the rest is borsh. The '
                .'account order and the signer and writable flags are the builder\'s, unedited, which is what '
                .'makes this a check on the library rather than a description of it.',
        ];
    }

    /**
     * Every address this request already knows, by alias.
     *
     * Matching by address rather than by position, because the instruction's
     * account order belongs to the library and a panel that assumed it would
     * mislabel every row the day it changed — silently, and in the one section
     * whose whole purpose is to be checkable.
     *
     * @return array<string, string> address => alias
     */
    private function aliasesFor(?SiteState $state, ?PayerState $payer): array
    {
        $program = $this->config->program();
        $aliases = [
            $program->id => Alias::for(Alias::PROGRAM, $program->id),
            $program->tokenProgram => Alias::for(Alias::TOKEN_PROGRAM, $program->tokenProgram),
        ];

        if ($state !== null) {
            $aliases[$state->address] = Alias::for(Alias::SITE, $state->address);
            $aliases[$state->site->mint] = Alias::for(Alias::MINT, $state->site->mint);
            $aliases[$state->site->treasury] = Alias::for(Alias::TREASURY, $state->site->treasury);
        }

        if ($payer !== null) {
            $aliases[$payer->wallet] = Alias::for(Alias::PAYER, $payer->wallet);
            $aliases[$payer->tokenAccount] = Alias::for(Alias::PAYER_TOKEN_ACCOUNT, $payer->tokenAccount);
            $aliases[$payer->contractAddress] = Alias::for(Alias::CONTRACT, $payer->contractAddress);
        }

        return $aliases;
    }

    /** @param array<string, string> $aliases */
    private function named(string $address, array $aliases): string
    {
        // An address with no alias is shown bare rather than given one on the
        // spot: §9's aliases are stable per address across sessions, and one
        // invented here for a role this panel could not identify would look
        // exactly like the stable kind.
        return isset($aliases[$address]) ? $aliases[$address].'  '.$address : $address;
    }

    /**
     * Preflight, for this request (SPEC §9).
     *
     * Six answers from `SolPay\Core\Preflight`, each beside the check in the
     * program it mirrors. The mirroring is the point and it is also the risk:
     * the library's arithmetic is a **copy** of the program's, made because
     * this package cannot call into it, and a copy can drift. `Preflight`'s
     * own docblock says so, and names the conformance run that pins it. What
     * this panel adds is the other direction — a reader who does not trust
     * either can compare each answer against the account fields two sections
     * above and do the arithmetic themselves.
     *
     * These are predictions, not decisions. Every one of them is what the site
     * asked *before* spending a fee to find out; the program checks the same
     * things again and its answer is the one that charges. Where the two
     * disagree, the program is right and this is a bug.
     *
     * `charge(1)` rather than `charge(n)` because §9 says so and because one
     * view is the unit the price is quoted in. §7.4's seven-view advance
     * multiplies this row; it does not change it.
     *
     * @param callable(int): string $amount both unit forms, at the mint's own decimals
     *
     * @return array{heading: string, rows: list<array{0: string, 1: string, 2?: string}>, note?: string}
     */
    private function preflight(SiteState $state, PayerState $payer, callable $amount): array
    {
        $site = $state->site;
        $contract = $payer->contract;

        $charge = Preflight::charge($site, 1);

        $rows = [[
            'charge(1)',
            $charge === null ? 'overflows' : $amount($charge),
            'page_price × page_views',
        ]];

        if ($contract === null) {
            // Three of the six take a `Contract` and there is not one. Saying
            // so beats printing a zero that reads like an answer.
            $rows[] = ['can_meter', 'no contract — nothing to meter against', 'require!(new_used <= limit)'];
            $rows[] = ['will_settle', 'no contract', 'unpaid >= collection_threshold'];
            $rows[] = ['views_remaining', 'no contract', '(limit - used) / page_price'];
        } else {
            $blocked = Preflight::canMeter($contract, $site, 1);
            $rows[] = [
                'can_meter',
                $blocked === null
                    ? 'yes — this charge fits under the limit'
                    : 'no — '.$blocked->kind->name.': '.$blocked,
                'require!(new_used <= limit, LimitReached)',
            ];

            $settles = Preflight::willSettle($contract, $site, 1);
            $rows[] = [
                'will_settle',
                $settles
                    ? 'yes — this call moves money'
                    : 'no — it accrues usage and transfers nothing',
                'unpaid >= collection_threshold',
            ];

            $rows[] = [
                'views_remaining',
                sprintf('%d', Preflight::viewsRemaining($contract, $site)),
                '(limit - used) / page_price',
            ];
        }

        $floor = Preflight::limitFloor($site, $contract);
        $rows[] = [
            'limit_floor',
            $amount($floor),
            'max(min_limit, unpaid carried forward)',
        ];

        $rows[] = [
            'required_allowance',
            $amount(Preflight::requiredAllowance($floor)),
            'the SPL delegated amount checked at open and at renew',
        ];

        return [
            'heading' => 'Preflight, for this request',
            'rows' => $rows,
            'note' => 'Asked before a fee was spent finding out, from the same account fields shown above. The '
                .'program checks all of it again and its answer is the one that charges — these are predictions, '
                .'and where they disagree with the program the program is right. required_allowance is quoted '
                .'against limit_floor, which is the smallest limit you could authorize right now; authorize more '
                .'and the approval has to cover that instead.',
        ];
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
