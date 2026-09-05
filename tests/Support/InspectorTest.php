<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Chain\SiteState;
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

    public function testAFailedReadStillProducesAPanel(): void
    {
        $sections = $this->inspector()->sections(null, 'getMultipleAccounts: HTTP 429');

        self::assertSame('getMultipleAccounts: HTTP 429', $this->row($sections, 'This site, on chain', 'read failed'));
    }
}
