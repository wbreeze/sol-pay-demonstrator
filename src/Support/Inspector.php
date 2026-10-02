<?php

declare(strict_types=1);

namespace Newsprint\Support;

use Newsprint\Chain\ChargeFault;
use Newsprint\Chain\Keypair;
use Newsprint\Chain\MeterState;
use Newsprint\Chain\SiteState;
use Newsprint\Metering\MeterOutcome;
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
     * @return list<array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, claims?: string, link?: array{href: string, text: string, external?: bool}, event?: string}>
     */
    public function sections(?SiteState $state = null, ?string $error = null, ?MeterState $meter = null, ?MeterResult $result = null): array
    {
        $sections = $this->order($this->build($state, $error, $meter, $result));
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
     * Every long value, in full, defined once, at the top (2026-09-12).
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
     * @param list<array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, claims?: string, link?: array{href: string, text: string, external?: bool}, event?: string}> $sections
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

        // Renamed 2026-09-14. *What the short names mean* was the right title
        // for a table that only expanded abbreviations; since the meanings
        // moved onto the rows it states what each value **is**, in its
        // unabbreviated form, which is the job the heading now names. *The
        // address table* was the other candidate and was declined for one row:
        // the instruction's bytes are not an address, and they are the row the
        // panel deliberately made look like all the others.
        return ['heading' => 'The values, in full', 'names' => array_values($names)];
    }

    /**
     * @return list<array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, claims?: string, link?: array{href: string, text: string, external?: bool}, event?: string}>
     */
    private function build(?SiteState $state = null, ?string $error = null, ?MeterState $meter = null, ?MeterResult $result = null): array
    {
        $program = $this->config->program();
        $params = $this->config->siteParams();

        // One map for the whole panel, built before any row is. Every address
        // row resolves its short name *and* its derivation from this and from
        // nothing else.
        $known = $this->knownAddresses($state, $meter);

        $sections = [[
            'heading' => 'Deployment',
            'rows' => [
                ['metering program', $this->address($program->id, $known)],
                ['token program', $this->address($program->tokenProgram, $known)],
                ['cluster', 'devnet'],
                ['endpoint', $this->config->rpcUrl()],
            ],
        ]];

        // A test fault is on (`ChargeFault`). Said here, in the section that
        // describes what this copy is running against, so that nothing seen
        // with it on can be mistaken for the site's ordinary behaviour.
        $fault = ChargeFault::fromEnvironment($this->config->rpcUrl());
        if ($fault !== ChargeFault::None) {
            $sections[0]['rows'][] = [
                'test fault',
                $fault->value.' — article charges skip the endpoint\'s check ('.ChargeFault::VARIABLE.')',
            ];
        }

        // SPEC §12.6: the development wallet is shown when it is on, for the
        // same reason as the fault above. The request's own loopback check is
        // the route's; this row says what the configuration allows.
        if ((bool) ($this->config->development()['wallet'] ?? false)
            && str_contains((string) parse_url($this->config->rpcUrl(), PHP_URL_HOST), 'devnet')) {
            $path = $this->config->keypairPath('dev-wallet');
            $sections[0]['rows'][] = [
                'development wallet',
                'on — signs setups in place of a phone, for requests from this machine'
                    .(is_file($path) ? ' ('.Keypair::load($path)->address.')' : ''),
            ];
        }

        /*
         * The two alarm states keep a sentence, and it is in the row rather
         * than under the section (2026-09-14).
         *
         * Every other explanatory paragraph left this panel that afternoon and
         * went into `reading-the-inspector`, which the line at the top links
         * to. These two did not, and the reason is who is reading. The notes
         * elsewhere were background — why a figure is shown, what it means,
         * an argument about the design — met while things are working, and
         * they can wait for an article. These answer a question a reader has
         * in the second they read the words *read failed*: is this site
         * broken, and did it break me? Sending them off to a long piece to
         * find out that nothing is wrong inverts the cost of the answer.
         *
         * It is also where the panel is least able to speak for itself. In
         * both states there are no reader accounts and no prices from the
         * chain, so the panel is four rows and a table with two addresses in
         * it — least self-explanatory exactly where it used to explain most.
         *
         * **Restored as the row's own claim, not as the `note` that was
         * deleted.** Bringing the section-level key back would mean the
         * template branch and the stylesheet rule for eleven notes in order to
         * reinstate two. A third cell declared `'claims' => 'under'` is the
         * anatomy this panel already has, renders the same `.beneath` line the
         * addresses use, and reads as part of the report rather than as
         * commentary on it.
         */
        if ($error !== null) {
            $sections[] = [
                'heading' => 'This site, on chain',
                'claims' => 'under',
                'rows' => [[
                    'read failed',
                    $error,
                    'The page is still served; nothing here depends on the chain until a charge has to be made.',
                ]],
            ];

            return $sections;
        }

        if ($state === null) {
            $sections[] = [
                'heading' => 'This site, on chain',
                'claims' => 'under',
                'rows' => [[
                    'status',
                    'not provisioned',
                    'First-run setup has not created the mint, the treasury or the site account yet.',
                ]],
                // The sentence says what is missing and the link says where to
                // fix it. `index.php` and `meter.php` both offer the route as
                // well, and neither is on the page an operator is most likely
                // to be reading when they notice -- the panel is on all of
                // them. Carried here rather than in the sentence because the
                // third cell renders through `View::e` and a tag in it would
                // arrive on screen as a tag.
                'link' => ['href' => '/setup', 'text' => 'Run first-run setup'],
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
                ['item price', $amount($site->itemPrice)],
                ['collection threshold', $amount($site->collectionThreshold).sprintf('  — %d views', intdiv($site->collectionThreshold, max(1, $site->itemPrice)))],
                ['minimum limit', $amount($site->minLimit).sprintf('  — %d views', intdiv($site->minLimit, max(1, $site->itemPrice)))],
                ['bump', (string) $site->bump],
                ['mint decimals', $state->mintDecimals === null ? 'unread' : (string) $state->mintDecimals],
            ],
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
            ];
        }

        // Provenance made visible: config/site.php decided what setup wrote,
        // and the chain has been the authority ever since. If they disagree,
        // the number on every other page came from the chain, and this says so.
        $drift = [];
        foreach ([
            'item price' => [(int) $params['page_price'], $site->itemPrice],
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
            ];
        }

        if ($meter !== null) {
            $sections[] = $this->reader($meter, $amount, $known, $result?->awaiting() ?? false);
            $sections[] = $this->preflight($state, $meter, $amount);
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
     * **Which is why a transaction this server did not build shows less here,
     * and says so.** Under the delegate design the reader's own transactions
     * were compiled in the browser by the wasm client, and this server never
     * held those instruction objects. Their signature and their decoded event
     * were shown; their bytes were not, with a line saying who built them.
     * Under the fund design the server composes every transaction (SPEC
     * §6.3, §5.4), so that line now covers only a charge reported by a later
     * request. The
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
     * @return array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, claims?: string, link?: array{href: string, text: string, external?: bool}, event?: string, instructions?: string, carry?: string}
     */
    private function lastTransaction(MeterResult $result, array $known): array
    {
        $signature = (string) $result->signature;

        $rows = [
            ['signature', $signature],
            ['outcome', $result->outcome->value.' — '.$result->detail],
            ['items', (string) $result->items, match (true) {
                // A failed transaction moved nothing and emitted no event, so
                // there is nothing for the event row to settle either way.
                $result->outcome === MeterOutcome::Absorbed => 'failed, so nothing moved',
                $result->settles === true => 'this call settles',
                $result->settles === false => 'accrues only',
                default => 'the event says which',
            }],
        ];

        if ($result->instructions === [] && $result->earlier) {
            // Built and sent by the request whose page this one follows up.
            // The rows that page showed are carried across by `swap.js` when
            // they are still in the document (`carry` below); this row is
            // what stands in their place when they are not — a reload, or a
            // grant confirmed on a later visit.
            $rows[] = [
                'instructions',
                'built by the request that sent this charge, and shown on its page; this site keeps no copy',
                'signature and event only',
            ];
        } elseif ($result->instructions === []) {
            $rows[] = [
                'instructions',
                'not built by this request, so this server does not hold them',
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
                    // One colon per line: the meaning's. The old wording put a
                    // second one inside the derivation and the row read as two
                    // sentences fighting over which was the subject.
                    'note' => self::says(Alias::DATA, sprintf('%d bytes, an 8-byte discriminator then borsh', strlen($instruction->data))),
                ],
            ];
        }

        return [
            'heading' => 'The last transaction',
            'rows' => $rows,
            'link' => [
                'href' => 'https://explorer.solana.com/tx/'.rawurlencode($signature).'?cluster=devnet',
                'text' => 'This transaction on chain',
                // Said rather than guessed from the scheme: the template opens
                // a second tab for this and for nothing else.
                'external' => true,
            ],
            'event' => $signature,
            // Which instruction rows belong to which transaction, so a follow-up
            // can keep the ones its own request never held (2026-09-17). The
            // builders' output is shown by the request that built it and by no
            // other; a follow-up that replaced the panel would otherwise
            // replace the evidence with a sentence about it.
            ($result->earlier ? 'carry' : 'instructions') => $signature,
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
            // What the table says about it: what it is, and then where it came
            // from where there is anything to say. An address with no role in
            // the map used to get null here, and the silence was meant to read
            // as "there is nothing to say" — but it read as an omission, which
            // is the same complaint that put provenance in this table at all.
            // It now says what it is: an address this panel cannot place.
            'note' => $known[$address]['derivation'] ?? self::says(Alias::UNNAMED, null),
        ];
    }

    /**
     * The line under a value: what it is, then where it came from.
     *
     * Added 2026-09-14, and it replaced a table. The prefixes were explained
     * once — in SPEC §9, in the panel's preamble, in a table at the top of the
     * article about the panel — and a reader met them eleven at a time and
     * then had to carry them down the rows. Written here they arrive one at a
     * time, next to the address they are about, which is where the question
     * *what is this* actually gets asked.
     *
     * The colon is doing real work: the first clause is a claim about the
     * value, the second is a claim about how the value came to exist, and they
     * are answerable separately. Where there is no second clause the line is
     * just the first, rather than a meaning followed by a dangling colon.
     */
    private static function says(string $role, ?string $derivation): string
    {
        return $derivation === null
            ? Alias::meaning($role)
            : Alias::meaning($role).': '.$derivation;
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
     * would have said something false. The site, fund and meter PDAs are
     * derived by *this* program from seeds it chose. The treasury and the
     * fund's token account are derived by Solana's associated-token program,
     * which this site does not own and did not write — showing them alike
     * would quietly claim otherwise. The rest were never derived, for
     * different reasons: two are keypairs first-run setup generated, one is a
     * key this browser generated, one is a deployment address and one is a
     * constant every Solana cluster shares.
     *
     * So the third cell says which of those it is. Silence would have been
     * cheaper and would have read as "we did not bother" rather than "there is
     * nothing to derive", which is the more useful fact and the true one.
     *
     * @return array<string, array{alias: string, derivation: ?string}>
     */
    private function knownAddresses(?SiteState $state, ?MeterState $meter): array
    {
        $program = $this->config->program();

        // Keyed by role rather than by finished alias (2026-09-14): the row's
        // line needs the role's meaning as well as its syllable, and one map
        // that answers both cannot disagree with itself.
        $roles = [
            $program->id => Alias::PROGRAM,
            $program->tokenProgram => Alias::TOKEN_PROGRAM,
        ];

        if ($state !== null) {
            $roles[$state->address] = Alias::SITE;
            $roles[$state->site->mint] = Alias::MINT;
            $roles[$state->site->treasury] = Alias::TREASURY;
            // Three sections show this one: the site account's authority, the
            // treasury token account's owner, and the signer on the metering
            // call. Named once here, it is the same short name in all of them
            // — and if the treasury turns out to be owned by the site PDA
            // rather than the authority, that row draws SPDA instead, which is
            // also right and needs no change here.
            $roles[$state->site->authority] = Alias::AUTHORITY;
        }

        if ($meter !== null) {
            $roles[$meter->meterAddress] = Alias::METER;
            $roles[$meter->fund] = Alias::FUND;
            $roles[$meter->fundTokenAccount] = Alias::FUND_TOKEN_ACCOUNT;
            if ($meter->meter !== null) {
                $roles[$meter->meter->key] = Alias::BROWSER_KEY;
            }
        }

        // Seeds are written with the short names above rather than with
        // 44-character base58, so a reader can match every seed to the row it
        // names without comparing strings by eye — which is the whole argument
        // for having aliases at all (§9). Every role therefore has to be
        // placed before the sentences that quote its alias, which is why this
        // is two passes over the same addresses rather than one.
        $of = static fn (string $address): string => isset($roles[$address])
            ? Alias::for($roles[$address], $address)
            : $address;

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

        if ($meter !== null) {
            if ($meter->meter !== null) {
                $derivations[$meter->meter->key] = 'generated in this browser and kept there; the meter names it';
            }

            // Seeded by the site and the mint, so none can be written without
            // a site account to read them from. An unprovisioned copy has no
            // site and these rows go bare, which is correct: the addresses
            // would not exist either.
            if ($state !== null) {
                $derivations[$meter->fund] = sprintf(
                    '["fund", your wallet, %s, index] + bump, by %s',
                    $of($state->site->mint),
                    $of($program->id),
                );
                $derivations[$meter->fundTokenAccount] = $ata(
                    $of($meter->fund),
                    $of($program->tokenProgram),
                    $of($state->site->mint),
                );
                $derivations[$meter->meterAddress] = sprintf(
                    '["meter", %s, %s] + bump, by %s',
                    $of($state->address),
                    $of($meter->fund),
                    $of($program->id),
                );
            }
        }

        $known = [];
        foreach ($roles as $address => $role) {
            $known[$address] = [
                'alias' => Alias::for($role, $address),
                'derivation' => self::says($role, $derivations[$address] ?? null),
            ];
        }

        return $known;
    }

    /**
     * Preflight, for this request (SPEC §9).
     *
     * Five answers from `SolPay\Core\Preflight`, each beside the check in the
     * program it mirrors. The mirroring is the point and it is also the risk:
     * the library's arithmetic is a **copy** of the program's, made because
     * this package cannot call into it, and a copy can drift. `Preflight`'s
     * own docblock says so, and names the conformance run that pins it. What
     * this panel adds is the other direction — a reader who does not trust
     * either can compare each answer against the account fields two sections
     * above and do the arithmetic themselves.
     *
     * These are predictions, not decisions. Every one of them is what the site
     * worked out here rather than by sending the transaction to find out; the
     * program checks the same things again and its answer is the one that
     * charges. Where the two disagree, the program is right and this is a bug.
     *
     * **What asking here saves is a round trip, and only sometimes a fee**
     * (corrected 2026-09-14). The panel note that carried the same correction
     * was removed the same day, so its prose is in
     * `content/ReadingTheInspector.md` now, under *Preflight, for this
     * request*. The endpoint simulates before it forwards, so a call the
     * program would refuse is usually rejected there and never included —
     * free, and about a second. A fee is charged when the transaction lands
     * and *then* fails, which needs the state to move between that simulation
     * and inclusion.
     * `Meter` carries the argument in full. It is also the site's fee and not
     * the reader's, which that article says because a reader has no way to
     * know it.
     *
     * `charge(1)` rather than `charge(n)` because §9 says so and because one
     * view is the unit the price is quoted in. §7.4's seven-view advance
     * multiplies this row; it does not change it.
     *
     * `can_meter` asks about the expiry at this server's clock. The program
     * reads the cluster's, so near the expiry the two disagree by however far
     * apart the clocks are, and the program's answer is the one that counts.
     *
     * @param callable(int): string $amount both unit forms, at the mint's own decimals
     *
     * @return array{heading: string, rows: list<array{0: string, 1: string, 2?: string}>, claims: string}
     */
    private function preflight(SiteState $state, MeterState $read, callable $amount): array
    {
        $site = $state->site;
        $meter = $read->meter;
        $now = time();

        $charge = Preflight::charge($site, 1);

        $rows = [[
            'charge(1)',
            $charge === null ? 'overflows' : $amount($charge),
            'item_price × items',
        ]];

        if ($meter === null) {
            // Three of the five take a `Meter` and there is not one. Saying
            // so beats printing a zero that reads like an answer.
            $rows[] = ['can_meter', 'no meter — nothing to meter against', 'require!(now <= expiry); require!(new_used <= limit)'];
            $rows[] = ['will_settle', 'no meter', 'unpaid >= collection_threshold'];
            $rows[] = ['items_remaining', 'no meter', '(limit - used) / item_price'];
        } else {
            $blocked = Preflight::canMeter($meter, $site, 1, $now);
            $rows[] = [
                'can_meter',
                $blocked === null
                    ? 'yes — the meter is open and this charge fits under the limit'
                    : 'no — '.$blocked->kind->name.': '.$blocked,
                'require!(now <= expiry, Expired); require!(new_used <= limit, LimitReached)',
            ];

            $settles = Preflight::willSettle($meter, $site, 1);
            $rows[] = [
                'will_settle',
                $settles
                    ? 'yes — this call moves money'
                    : 'no — it accrues usage and transfers nothing',
                'unpaid >= collection_threshold',
            ];

            $rows[] = [
                'items_remaining',
                sprintf('%d', Preflight::itemsRemaining($meter, $site)),
                '(limit - used) / item_price',
            ];
        }

        $rows[] = [
            'limit_floor',
            $amount(Preflight::limitFloor($site, $meter)),
            'max(min_limit, unpaid carried forward)',
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
        ];
    }

    /**
     * You, on chain: the meter this browser holds and the fund it draws on.
     *
     * **The expiry and the key are the rows that matter here**, as the
     * delegate was before them. The key says which browser the meter answers
     * to, and a renewal from another device changes it. The expiry bounds
     * what a meter left on a machine can cost, whatever happens to the
     * machine (SPEC §5.5). The balance is the fund's token account's, read on
     * every request, because the money is there and not in the fund.
     *
     * @param callable(int): string $amount both unit forms, at the mint's own decimals
     * @param array<string, array{alias: string, derivation: ?string}> $known every address this request can name, for {@see address()}
     *
     * @return array{heading: string, rows: list<array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>}
     */
    private function reader(MeterState $read, callable $amount, array $known, bool $beforeCharge = false): array
    {
        $rows = [];

        // The charge this page was served on has not confirmed (§7.3,
        // 2026-09-17), so the figures below were read from accounts it may
        // not have reached yet. Worded so that it stays true after the
        // charge lands: this is when the reading was taken, not a status.
        if ($beforeCharge) {
            $rows[] = ['read', 'before the charge for this article confirmed — the figures may not include it'];
        }

        $rows[] = ['your meter', $this->address($read->meterAddress, $known, $read->meter !== null)];

        if ($read->meter !== null) {
            $meter = $read->meter;
            $rows[] = ['key', $this->address($meter->key, $known, false)];
            $rows[] = ['expiry', gmdate('Y-m-d H:i', $meter->expiry).' UTC'.($meter->expired(time()) ? ' — past' : '')];
            $rows[] = ['limit', $amount($meter->limit)];
            $rows[] = ['used', $amount($meter->used)];
            $rows[] = ['paid', $amount($meter->paid)];
            $rows[] = ['unpaid', $amount($meter->unpaid())];
        }

        $rows[] = ['your fund', $this->address($read->fund, $known)];
        $rows[] = [
            "the fund's token account",
            $this->address(
                $read->fundTokenAccount,
                $known,
                // Derived either way; only sometimes there. The explorer
                // link is dropped rather than pointed at "account not
                // found", and the row below says why in words.
                $read->funds !== null,
            ),
        ];
        $rows[] = $read->funds === null
            ? ['balance', 'no token account — the fund has never been opened in this mint']
            : ['balance', $amount($read->funds->amount)];

        return [
            'heading' => 'You, on chain',
            'rows' => $rows,
        ];
    }

    /** @param array<string, int|string> $params @return array{heading: string, rows: list<array{0: string, 1: string}>} */
    private function configuredPrices(array $params): array
    {
        $decimals = (int) $params['decimals'];

        return [
            'heading' => 'Site parameters, configured',
            'rows' => [
                ['item price', sprintf('%s %s  (%d base units)', Units::fromBaseUnits((int) $params['page_price'], $decimals), (string) $params['symbol'], (int) $params['page_price'])],
                ['collection threshold', sprintf('%s %s  (%d views)', Units::fromBaseUnits((int) $params['collection_threshold'], $decimals), (string) $params['symbol'], intdiv((int) $params['collection_threshold'], (int) $params['page_price']))],
                ['minimum limit', sprintf('%s %s  (%d views)', Units::fromBaseUnits((int) $params['min_limit'], $decimals), (string) $params['symbol'], intdiv((int) $params['min_limit'], (int) $params['page_price']))],
                ['mint decimals', (string) $decimals],
            ],
        ];
    }
}
