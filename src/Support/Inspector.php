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
     * The value is a string, or — for a row whose value *is* an address — the
     * shape {@see address()} returns. The label is then always the role, which
     * is the rework of 2026-09-08: an alias used to be the label on some rows
     * and a prefix inside the value on others, so the same thing had two
     * anatomies and the label column meant two different things.
     *
     * A section may also carry a `link` (§9 asks for the explorer beside a
     * signature) and an `event` — a signature whose decoded event the panel
     * fetches when it is opened rather than on the request that made it. See
     * {@see lastTransaction()} for why that read is deferred.
     *
     * @return list<array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, claims?: string, note?: string, link?: array{href: string, text: string}, event?: string}>
     */
    public function sections(?SiteState $state = null, ?string $error = null, ?PayerState $payer = null, ?MeterResult $result = null): array
    {
        $sections = $this->order($this->build($state, $error, $payer, $result));
        $names = $this->names($sections);

        return $names === null ? $sections : array_merge([$names], $sections);
    }

    /**
     * Most-changing first (2026-09-12).
     *
     * The panel was built outwards from the deployment — program, site,
     * treasury, then the reader, then this request — which is the order the
     * *system* is assembled in and the reverse of the order anybody reads it
     * in. A reader opening the panel twice in a row is looking for what moved:
     * the preflight numbers and the transaction change on every request, the
     * reader's own accounts change when they are charged, the treasury when a
     * settle lands, the site account when setup runs, and the deployment
     * never. Scrolling past four sections of constants to reach the two that
     * moved is the whole of the complaint.
     *
     * The table of short names stays above all of it: it is the key to
     * everything below, and a key belongs at the top whether or not it
     * changed.
     *
     * `Configuration drift` is the exception to the gradient and the one
     * section here that is not a readout. It appears only when the chain and
     * `config/site.php` disagree, so by rate of change it belongs at the
     * bottom; it sits with the site account it disagrees with, which is where
     * a reader can check the claim.
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return list<array<string, mixed>>
     */
    private function order(array $sections): array
    {
        $order = [
            // A site that could not be read, or is not set up yet, has one
            // thing to say and everything else on the page is furniture.
            'This site, on chain',
            'Preflight, for this request',
            'The last transaction',
            'You, on chain',
            'Treasury',
            'Configuration drift',
            'Site account, decoded',
            'Deployment',
            'Site parameters, configured',
        ];

        // A stable sort, so a heading not named above keeps its place rather
        // than being flung to the end by a comparison it never entered.
        usort($sections, static function (array $a, array $b) use ($order): int {
            $rank = static function (array $section) use ($order): int {
                $at = array_search($section['heading'], $order, true);

                return $at === false ? count($order) : $at;
            };

            return $rank($a) <=> $rank($b);
        });

        return $sections;
    }

    /**
     * The short names used below, defined once, at the top (2026-09-12).
     *
     * Every address used to carry its own base58 and its own copy button at
     * every sighting. A charging view showed **21 addresses of 9 distinct
     * ones** that way — the authority, the treasury and the contract three
     * times each — and the worst of it was the transaction's account list,
     * eight rows of 44 characters where the question a reader has is *which
     * accounts, in what order*.
     *
     * Two things made the repetition worse than verbose. A row's third cell
     * holds either the check it mirrors or the address's provenance, and the
     * mirrored check wins — so every account row in the last transaction
     * carried `signer, writable` and silently dropped the derivation §9 asks
     * for beside every address. And the seeds are written in short names, so a
     * derivation cell in one section named rows in another. Gathered here,
     * every address states its provenance exactly once, and a seed names a row
     * three lines up.
     *
     * Order of first appearance, which is the order a reader met them in.
     *
     * @param list<array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, claims?: string, note?: string, link?: array{href: string, text: string}, event?: string}> $sections
     *
     * @return ?array{heading: string, names: list<array{value: string, alias: string, explorer: bool, note: ?string}>}
     */
    private function names(array $sections): ?array
    {
        $names = [];
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                if (is_array($row[1])) {
                    // Keyed by the value, so the same address seen in three
                    // sections is one row here — which is the whole point, and
                    // it is the same matching-by-value rule the panel already
                    // uses to label a transaction's accounts.
                    $names[$row[1]['value']] ??= $row[1];
                }
            }
        }

        if ($names === []) {
            return null;
        }

        return ['heading' => 'What the short names mean', 'names' => array_values($names)];
    }

    /**
     * @return list<array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, claims?: string, note?: string, link?: array{href: string, text: string}, event?: string}>
     */
    private function build(?SiteState $state = null, ?string $error = null, ?PayerState $payer = null, ?MeterResult $result = null): array
    {
        $program = $this->config->program();
        $params = $this->config->siteParams();

        // One map for the whole panel, built before any row is. Every address
        // row resolves its short name *and* its derivation from this and from
        // nothing else.
        $known = $this->knownAddresses($state, $payer);

        $sections = [[
            'heading' => 'Deployment',
            'rows' => [
                ['metering program', $this->address($program->id, $known)],
                ['token program', $this->address($program->tokenProgram, $known)],
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
                ['site account', $this->address($state->address, $known)],
                ['authority', $this->address($site->authority, $known)],
                ['mint', $this->address($site->mint, $known)],
                ['treasury', $this->address($site->treasury, $known)],
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
                    ['treasury token account', $this->address($site->treasury, $known)],
                    ['balance', $amount($state->treasury->amount)],
                    ['owner', $this->address($state->treasury->owner, $known)],
                    ['delegate', $state->treasury->delegate === null
                        ? 'none'
                        : $this->address($state->treasury->delegate, $known)],
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
            $sections[] = $this->reader($payer, $amount, $known);
            $sections[] = $this->preflight($state, $payer, $amount);
        }

        if ($result !== null && $result->signature !== null) {
            $sections[] = $this->lastTransaction($result, $known);
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
     * @param array<string, array{alias: string, derivation: ?string}> $known every address this request can name
     *
     * @return array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, claims?: string, note?: string, link?: array{href: string, text: string}, event?: string}
     */
    private function lastTransaction(MeterResult $result, array $known): array
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

        foreach ($result->instructions as $i => $instruction) {
            $n = $i + 1;
            $rows[] = [
                sprintf('ix %d · program', $n),
                $this->address($instruction->programId, $known),
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
                    $this->address($account->pubkey, $known),
                    $flags === [] ? 'readonly' : implode(', ', $flags),
                ];
            }

            // The one value in the panel that is not an address and is just
            // as unreadable, so it follows the same rule: a short name here,
            // the bytes in the table with everything else long.
            $data = bin2hex($instruction->data);
            $rows[] = [
                sprintf('ix %d · data', $n),
                [
                    'value' => strlen($data) > 16 ? substr($data, 0, 16).' '.substr($data, 16) : $data,
                    'alias' => Alias::for(Alias::DATA, $instruction->data),
                    'explorer' => false,
                    'note' => sprintf('%d bytes: 8-byte discriminator, then borsh', strlen($instruction->data)),
                ],
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
     * One address, as the panel's rows carry it.
     *
     * **The rework of 2026-09-08.** Until now an alias lived in one of two
     * places depending on the row: it *was* the label on an address row
     * (`PAYRfig` → the base58), and it was a prefix jammed into the value with
     * two spaces on a `delegate` or an instruction account. Same thing, two
     * anatomies, and the label column meant "role" on some rows and "alias" on
     * others. Now the label is always the role and the value is always this:
     * alias, address, and the controls that belong to an address.
     *
     * That is worth more than tidiness. §9 wants a copy button, an explorer
     * link and eventually a derivation on *every* address, and each of those
     * needs somewhere to hang. One anatomy is one place to hang them.
     *
     * `explorer` is false where this site already knows the account is not
     * there — a contract PDA before it is opened, a token account before the
     * faucet. The address is real (it is derived), but the explorer would show
     * "account not found", and a link that lands on nothing is worse than no
     * link. The row says so in words beside it.
     *
     * **The alias is looked up, never passed in.** That changed on 2026-09-08
     * and it closed a real gap rather than tidying one. When each call site
     * chose its own alias, the site authority — which is the site account's
     * `authority`, the treasury's `owner` and the signer of every metering
     * call — went unnamed in all three places, because no single call site was
     * obviously the one that should have named it. One map, consulted here,
     * cannot have that shape of hole: an address either has a role in the map
     * or it does not, and the answer is the same in every section it appears
     * in. Which is §9's whole claim for aliases — *the same address always
     * draws the same alias* — enforced instead of hoped for.
     *
     * @param array<string, array{alias: string, derivation: ?string}> $known
     *
     * @return array{value: string, alias: string, explorer: bool, note: ?string}
     */
    private function address(string $address, array $known, bool $onChain = true): array
    {
        return [
            'value' => $address,
            // Every address has a short name now, because every address has to
            // be findable in the table where the base58 lives. An address with
            // no role in the map gets the `ACCT` prefix rather than a role's —
            // see {@see Alias::UNNAMED}: the objection to inventing one was
            // that it would look like the stable kind, and a prefix that says
            // "unplaced" answers it without leaving the address undefined.
            'alias' => $known[$address]['alias'] ?? Alias::for(Alias::UNNAMED, $address),
            'explorer' => $onChain,
            // What the table says about it: the derivation where there is one,
            // where it came from where there is not, and null for an address
            // this panel cannot place — which is the honest answer and reads
            // differently from "derived from nothing".
            'note' => $known[$address]['derivation'] ?? null,
        ];
    }

    /**
     * Every address this request already knows: its short name, and the
     * derivation that produced it where one did.
     *
     * Matching by address rather than by position, because the instruction's
     * account order belongs to the library and a panel that assumed it would
     * mislabel every row the day it changed — silently, and in the one section
     * whose whole purpose is to be checkable.
     *
     * An address with no entry here is rendered bare rather than given an
     * alias on the spot: §9's aliases are stable per address across sessions,
     * and one invented for a role this panel could not identify would look
     * exactly like the stable kind.
     *
     * **Only some of these addresses are derived, and not all by the same
     * program.** §9 asked for "the derivation that produced it" as though that
     * were one uniform thing. It is not, and the uniform rendering it imagined
     * would have said something false. The site and contract PDAs are derived
     * by *this* program from seeds it chose. The treasury and the reader's
     * token account are derived by Solana's associated-token program, which
     * this site does not own and did not write — showing all four alike would
     * quietly claim otherwise. The remaining five were never derived, for
     * three different reasons: two are keypairs first-run setup generated, one
     * is the reader's own wallet, one is a deployment address and one is a
     * constant every Solana cluster shares.
     *
     * So the third cell says which of those it is. Silence would have been
     * cheaper and would have read as "we did not bother" rather than "there is
     * nothing to derive", which is the more useful fact and the true one.
     *
     * @return array<string, array{alias: string, derivation: ?string}>
     */
    private function knownAddresses(?SiteState $state, ?PayerState $payer): array
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
            // Three sections show this one: the site account's authority, the
            // treasury token account's owner, and the signer on the metering
            // call. Named once here, it is the same short name in all of them
            // — and if the treasury turns out to be owned by the site PDA
            // rather than the authority, that row draws SPDA instead, which is
            // also right and needs no change here.
            $aliases[$state->site->authority] = Alias::for(Alias::AUTHORITY, $state->site->authority);
        }

        if ($payer !== null) {
            $aliases[$payer->wallet] = Alias::for(Alias::PAYER, $payer->wallet);
            $aliases[$payer->tokenAccount] = Alias::for(Alias::PAYER_TOKEN_ACCOUNT, $payer->tokenAccount);
            $aliases[$payer->contractAddress] = Alias::for(Alias::CONTRACT, $payer->contractAddress);
        }

        // Seeds are written with the short names above rather than with
        // 44-character base58, so a reader can match every seed to the row it
        // names without comparing strings by eye — which is the whole argument
        // for having aliases at all (§9). The aliases therefore have to exist
        // before the sentences that quote them, which is why this is two
        // passes over the same addresses rather than one.
        $of = static fn (string $address): string => $aliases[$address] ?? $address;

        $ata = static fn (string $owner, string $tokenProgram, string $mint): string => sprintf(
            '[%s, %s, %s] + bump, by the associated-token program',
            $owner,
            $tokenProgram,
            $mint,
        );

        $derivations = [
            // Not derived: a program is deployed *to* an address, and this one
            // is in config/site.php because someone put it there.
            $program->id => 'deployed to this cluster; nothing derived it',
            $program->tokenProgram => 'a fixed address, the same on every Solana cluster',
        ];

        if ($state !== null) {
            $derivations[$state->address] = sprintf(
                '["site", %s] + bump, by %s',
                $of($state->site->authority),
                $of($program->id),
            );
            $derivations[$state->site->authority] = 'a keypair first-run setup generated; this site holds it';
            $derivations[$state->site->mint] = 'a keypair first-run setup generated; it has no seeds';
            $derivations[$state->site->treasury] = $ata(
                $of($state->site->authority),
                $of($program->tokenProgram),
                $of($state->site->mint),
            );
        }

        if ($payer !== null) {
            $derivations[$payer->wallet] = "your wallet's own public key; nothing derived it";

            // Both of these are seeded by the mint, so neither can be written
            // without a site account to read it from. An unprovisioned copy
            // has no mint and these rows go bare, which is correct: the
            // addresses would not exist either.
            if ($state !== null) {
                $derivations[$payer->tokenAccount] = $ata(
                    $of($payer->wallet),
                    $of($program->tokenProgram),
                    $of($state->site->mint),
                );
                $derivations[$payer->contractAddress] = sprintf(
                    '["contract", %s, %s] + bump, by %s',
                    $of($state->address),
                    $of($payer->wallet),
                    $of($program->id),
                );
            }
        }

        $known = [];
        foreach ($aliases as $address => $alias) {
            $known[$address] = ['alias' => $alias, 'derivation' => $derivations[$address] ?? null];
        }

        return $known;
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
     * @return array{heading: string, rows: list<array{0: string, 1: string, 2?: string}>, claims: string, note?: string}
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
            // The third cell here is a sentence, so it goes under the value
            // rather than beside it (2026-09-12). Declared by the section
            // rather than guessed from the length of the text, which would
            // make the panel's anatomy depend on how a claim happened to be
            // worded. The last transaction's `signer, writable` stays beside:
            // two words read as a suffix, a sentence reads as a caption.
            'claims' => 'under',
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
     * @param array<string, array{alias: string, derivation: ?string}> $known every address this request can name, for {@see address()}
     *
     * @return array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, note?: string}
     */
    private function reader(PayerState $payer, callable $amount, array $known): array
    {
        $rows = [
            ['your wallet', $this->address($payer->wallet, $known)],
            [
                'your token account',
                $this->address(
                    $payer->tokenAccount,
                    $known,
                    // Derived either way; only sometimes there. The explorer
                    // link is dropped rather than pointed at "account not
                    // found", and the row below says why in words.
                    $payer->funds !== null,
                ),
            ],
        ];

        if ($payer->funds === null) {
            $rows[] = ['token account', 'does not exist yet — the faucet creates it'];
        } else {
            $rows[] = ['balance', $amount($payer->funds->amount)];
            $rows[] = [
                'delegate',
                $payer->funds->delegate === null
                    ? 'none — nothing may draw from this account'
                    : $this->address($payer->funds->delegate, $known),
            ];
            $rows[] = ['approved', $amount($payer->funds->delegatedAmount)];
        }

        if ($payer->contract === null) {
            $rows[] = [
                'your contract',
                $this->address($payer->contractAddress, $known, false),
            ];
            $rows[] = ['on chain', 'not yet — the address is derived, the account is not there'];
        } else {
            $rows[] = [
                'your contract',
                $this->address($payer->contractAddress, $known),
            ];
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
