<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Chain\SiteState;
use Newsprint\Support\Alias;
use Newsprint\Support\Config;
use Newsprint\Support\Inspector;
use PHPUnit\Framework\TestCase;
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
     * The third cell on an address row: the derivation that produced the
     * address, or — where nothing derived it — where it came from instead.
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
                    return $row[1]['derivation'];
                }
            }
        }

        return null;
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

    public function testAFailedReadStillProducesAPanel(): void
    {
        $sections = $this->inspector()->sections(null, 'getMultipleAccounts: HTTP 429');

        self::assertSame('getMultipleAccounts: HTTP 429', $this->row($sections, 'This site, on chain', 'read failed'));
    }
}
