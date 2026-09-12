<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Chain\SiteState;
use Newsprint\Support\Alias;
use Newsprint\Support\Config;
use Newsprint\Support\Inspector;
use PHPUnit\Framework\TestCase;
use Newsprint\Metering\MeterResult;
use Newsprint\Chain\PayerState;
use SolPay\Core\AccountMeta;
use SolPay\Core\Contract;
use SolPay\Core\TokenAccount;
use SolPay\Core\Instruction;
use SolPay\Core\Site;

/**
 * SPEC §2's claim 7 — every number came from an account — is only worth
 * something if the panel actually shows the account's numbers rather than the
 * ones the server was configured with. These are the two halves of that: both
 * unit forms present, and a visible complaint when config and chain disagree.
 */
final class InspectorTest extends TestCase
{
    private const SITE = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';

    private function inspector(): Inspector
    {
        return new Inspector(Config::load(dirname(__DIR__, 2)));
    }

    private function state(int $pagePrice = 10_000, int $threshold = 100_000, int $minLimit = 500_000): SiteState
    {
        return new SiteState(
            self::SITE,
            new Site(
                authority: '163aJWGmry7Q2gWjtxmTbdC7NGFc7FecSN1gfpNUgRt',
                mint: 'MintI1111111111111111111111111111111111111',
                treasury: 'TrSy11111111111111111111111111111111111111',
                pagePrice: $pagePrice,
                collectionThreshold: $threshold,
                minLimit: $minLimit,
                bump: 254,
            ),
            null,
            6,
        );
    }

    /** @param list<array{heading: string, rows: list<array{0: string, 1: string}>, note?: string}> $sections */
    private function row(array $sections, string $heading, string $label): ?string
    {
        foreach ($sections as $section) {
            if ($section['heading'] !== $heading) {
                continue;
            }
            foreach ($section['rows'] as [$name, $value]) {
                if ($name === $label) {
                    return $value;
                }
            }
        }

        return null;
    }

    public function testAmountsAppearInBothForms(): void
    {
        $sections = $this->inspector()->sections($this->state());

        // §6.2's scaling error — 50 becoming 50,000,000 — is invisible until
        // the two forms sit side by side, so neither is optional.
        self::assertSame('0.01 DEMO  (10000 base units)', $this->row($sections, 'Site account, decoded', 'page price'));
        self::assertSame('0.1 DEMO  (100000 base units)  — 10 views', $this->row($sections, 'Site account, decoded', 'collection threshold'));
        self::assertSame('0.5 DEMO  (500000 base units)  — 50 views', $this->row($sections, 'Site account, decoded', 'minimum limit'));
    }

    public function testTheDecodedSectionCarriesTheAccountsOwnFields(): void
    {
        $sections = $this->inspector()->sections($this->state());

        self::assertSame('254', $this->row($sections, 'Site account, decoded', 'bump'));
        self::assertSame('6', $this->row($sections, 'Site account, decoded', 'mint decimals'));
    }

    public function testDriftIsReportedWhenTheChainDisagreesWithTheConfig(): void
    {
        // config/site.php says 10_000; this site was initialised at a
        // different price and initialize_site runs once.
        $sections = $this->inspector()->sections($this->state(pagePrice: 20_000));

        self::assertSame(
            'config says 10000, the chain says 20000',
            $this->row($sections, 'Configuration drift', 'page price'),
        );
    }

    public function testThereIsNoDriftSectionWhenTheyAgree(): void
    {
        $headings = array_column($this->inspector()->sections($this->state()), 'heading');

        self::assertNotContains('Configuration drift', $headings);
    }

    /**
     * What the panel says about where an address came from.
     *
     * Since 2026-09-12 that is not on the row: the row carries a short name,
     * and the table at the top of the panel carries the address, the explorer
     * link, the copy button and this. So the lookup goes the way a reader's
     * eye does — find the row, take its short name's value, read the table.
     *
     * @param list<array{heading: string, rows: list<array<int, mixed>>}> $sections
     */
    private function derivation(array $sections, string $heading, string $label): ?string
    {
        foreach ($sections as $section) {
            if ($section['heading'] !== $heading) {
                continue;
            }
            foreach ($section['rows'] as $row) {
                if ($row[0] === $label && is_array($row[1])) {
                    return $this->names($sections)[$row[1]['value']]['note'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * The table of short names, keyed by value.
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return array<string, array{value: string, alias: string, explorer: bool, note: ?string}>
     */
    private function names(array $sections): array
    {
        foreach ($sections as $section) {
            if (isset($section['names'])) {
                return array_column($section['names'], null, 'value');
            }
        }

        return [];
    }

    private function programAlias(): string
    {
        $id = Config::load(dirname(__DIR__, 2))->program()->id;

        return Alias::for(Alias::PROGRAM, $id);
    }

    /**
     * §9 asked for "the derivation that produced it" as though that were one
     * uniform thing, and the difference is the part worth a test.
     *
     * The site PDA is derived by *this* program from seeds it chose. The
     * treasury is an associated token account, derived by a Solana program
     * this site does not own and did not write. A panel that rendered both
     * simply as "derived" would be quietly claiming authorship of an address
     * it never computed — which is the failure this asserts against, in the
     * only way that can tell the two apart: the deriving program is named, and
     * on the treasury row it is *not* ours.
     */
    public function testADerivedAddressNamesTheProgramThatDerivedIt(): void
    {
        $sections = $this->inspector()->sections($this->state());

        $site = $this->derivation($sections, 'Site account, decoded', 'site account');
        self::assertNotNull($site, 'a PDA has seeds and they belong on the row');
        self::assertStringStartsWith('["site", ', $site, 'the literal seed first, as the program writes it');
        self::assertStringContainsString('by '.$this->programAlias(), $site);

        $treasury = $this->derivation($sections, 'Site account, decoded', 'treasury');
        self::assertNotNull($treasury);
        self::assertStringContainsString('by the associated-token program', $treasury);
        self::assertStringNotContainsString(
            $this->programAlias(),
            $treasury,
            'the treasury is not derived by this site\'s program and must not say it is',
        );
    }

    /**
     * The other half, and the reason silence was rejected: an address with no
     * derivation has a provenance instead, and a blank cell would read as "we
     * did not bother" rather than "there was nothing to derive".
     */
    public function testAnAddressThatWasNeverDerivedSaysSoRatherThanGoingBlank(): void
    {
        $sections = $this->inspector()->sections($this->state());

        $mint = $this->derivation($sections, 'Site account, decoded', 'mint');
        self::assertNotNull($mint, 'silence here reads as an omission, not as a fact');
        self::assertStringNotContainsString('[', $mint, 'a keypair has no seeds to show');
        self::assertStringNotContainsString(' by ', $mint, 'and no program derived it');
    }

    /**
     * Every value long enough to be unreadable is defined once, at the top.
     *
     * This is the whole of the 2026-09-12 rework and it has two halves, both
     * asserted here: nothing in a section carries a base58 any more, and every
     * short name a section uses resolves to a row in the table. A panel that
     * satisfied only the first would be a panel of names nobody can look up.
     *
     * The fixture has to be the crowded case — a payer and a transaction —
     * because the deduplication is the point: that view shows the authority,
     * the treasury and the contract three times each.
     */
    public function testEveryShortNameUsedIsDefinedExactlyOnce(): void
    {
        $sections = $this->inspector()->sections($this->state(), null, null, $this->metered());
        $names = $this->names($sections);

        $used = [];
        foreach ($sections as $section) {
            foreach ($section['rows'] ?? [] as $row) {
                if (is_array($row[1])) {
                    $used[] = $row[1]['value'];
                    self::assertArrayHasKey($row[1]['value'], $names, $row[0].' is not in the table');
                }
            }
        }

        self::assertGreaterThan(count($names), count($used), 'the fixture must repeat an address, or this proves nothing');
        self::assertSame(array_values(array_unique($used)), array_keys($names), 'in order of first appearance, once each');

        // That the *rendered* sections write no base58 is a claim about the
        // templates, and `TemplateRenderTest` makes it there — the rows still
        // carry the value, because the table is built from them.
    }

    /**
     * An address with no role still gets a name, and does not borrow one.
     *
     * Rendering it bare was right while every row carried its own base58; with
     * the addresses gathered into a table it would be the one value on the
     * page that could not be looked up. The prefix is what keeps it honest —
     * `ACCT` claims nothing about the account, where `SPDA` on an address this
     * panel could not place would be a guess wearing a fact's clothes.
     */
    public function testAnAddressThePanelCannotPlaceIsNamedWithoutClaimingARole(): void
    {
        $stranger = 'SysvarRent111111111111111111111111111111111';
        $sections = $this->inspector()->sections($this->state(), null, null, $this->metered($stranger));
        $names = $this->names($sections);

        self::assertArrayHasKey($stranger, $names);
        self::assertStringStartsWith(Alias::UNNAMED, $names[$stranger]['alias']);
        self::assertNull($names[$stranger]['note'], 'nothing is known about it, and the table says so by saying nothing');

        foreach ([Alias::SITE, Alias::AUTHORITY, Alias::MINT, Alias::PAYER] as $role) {
            self::assertStringStartsNotWith($role, $names[$stranger]['alias']);
        }

        // Stable, like every other alias: the same address draws the same
        // name, which is what makes it worth printing at all.
        self::assertSame($names[$stranger]['alias'], Alias::for(Alias::UNNAMED, $stranger));
    }

    /** The instruction's bytes are not an address and are just as unreadable. */
    public function testTheInstructionDataIsNamedLikeEverythingElseLong(): void
    {
        $names = $this->names($this->inspector()->sections($this->state(), null, null, $this->metered()));

        $data = array_values(array_filter($names, static fn (array $n): bool => str_starts_with($n['alias'], Alias::DATA)));
        self::assertCount(1, $data);
        self::assertFalse($data[0]['explorer'], 'there is nothing at an explorer to show for it');
        self::assertStringContainsString('borsh', (string) $data[0]['note']);
    }

    /** A reader with a contract, so the crowded panel is the one under test. */
    private function payer(): PayerState
    {
        $site = $this->state()->site;
        $wallet = 'BFT5EZLV7eWhwX4jjRP7JJDuJYoCQRmvmzuDUBbvSMqR';
        $contract = 'Fgm6costwpmn4d1CTqdM5su8jBptdwnW134cNoFixgqs';
        $token = '3KDoatBW3VwL5tyKLnCrWreKSsAXEhymyCUpei6eLd7v';

        return new PayerState(
            $wallet,
            $contract,
            $token,
            new Contract(self::SITE, $wallet, 500_000, 420_000, 280_000, 255),
            new TokenAccount($site->mint, $wallet, 210_000, $contract, 180_000),
            $site,
            6,
        );
    }

    /** A metered result carrying one instruction, optionally naming a stranger. */
    private function metered(?string $extra = null): MeterResult
    {
        $program = Config::load(dirname(__DIR__, 2))->program();
        $accounts = [new AccountMeta(self::SITE, false, false)];
        if ($extra !== null) {
            $accounts[] = new AccountMeta($extra, false, false);
        }

        return MeterResult::metered(
            'FKb3eeBwq5fXi4M6NhLY5tkstxRi2N6VoKtPpkG23ASSxyz',
            10_000,
            true,
            1,
            [new Instruction($program->id, $accounts, (string) hex2bin('1e8e96a17c2e1d7e'))],
        );
    }

    /**
     * Most-changing first, with the key to all of it above.
     *
     * The panel used to be built outwards from the deployment, which is the
     * order the system is assembled in and the reverse of the order it is
     * read in: a reader opening it twice wants what moved, and the deployment
     * never moves. Asserted as a whole sequence rather than pairwise, because
     * the thing that goes wrong here is a section being *added* in the wrong
     * place, which no pair of assertions about the old ones would catch.
     */
    public function testTheSectionsRunFromWhatChangesToWhatDoesNot(): void
    {
        // Every section the panel has, so the sequence is asserted whole: a
        // fixture missing one would let a section be inserted in the wrong
        // place without failing anything.
        $site = $this->state()->site;
        $state = new SiteState(self::SITE, $site, new TokenAccount($site->mint, $site->authority, 4_200_000, null, 0), 6);
        $sections = $this->inspector()->sections($state, null, $this->payer(), $this->metered());

        self::assertSame([
            'What the short names mean',
            'Preflight, for this request',
            'The last transaction',
            'You, on chain',
            'Treasury',
            'Site account, decoded',
            'Deployment',
        ], array_column($sections, 'heading'));
    }

    /** The drift alarm sits with the account it disagrees with. */
    public function testDriftIsShownBesideTheSiteAccount(): void
    {
        $headings = array_column($this->inspector()->sections($this->state(pagePrice: 20_000)), 'heading');

        self::assertSame(
            array_search('Site account, decoded', $headings, true) - 1,
            array_search('Configuration drift', $headings, true),
        );
    }

    public function testAFailedReadStillProducesAPanel(): void
    {
        $sections = $this->inspector()->sections(null, 'getMultipleAccounts: HTTP 429');

        self::assertSame('getMultipleAccounts: HTTP 429', $this->row($sections, 'This site, on chain', 'read failed'));
    }
}
