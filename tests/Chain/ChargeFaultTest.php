<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\ChargeFault;
use Newsprint\Support\Config;
use Newsprint\Support\Inspector;
use PHPUnit\Framework\TestCase;

/**
 * The devnet test fault (2026-09-17): off unless asked for, and refused
 * anywhere it could break a real charge.
 *
 * A fault that could be switched on against a real endpoint by one stray
 * environment variable would be the most expensive line in this repository,
 * so the refusals are the tests that matter here.
 */
final class ChargeFaultTest extends TestCase
{
    private const DEVNET = 'https://api.devnet.solana.com';

    protected function tearDown(): void
    {
        putenv(ChargeFault::VARIABLE);
    }

    public function testOffUnlessAskedFor(): void
    {
        putenv(ChargeFault::VARIABLE);
        self::assertSame(ChargeFault::None, ChargeFault::fromEnvironment(self::DEVNET));

        putenv(ChargeFault::VARIABLE.'=');
        self::assertSame(ChargeFault::None, ChargeFault::fromEnvironment(self::DEVNET));
        self::assertFalse(ChargeFault::None->skipsPreflight(), 'the ordinary charge keeps the endpoint\'s check');
    }

    public function testBothFaultsAreRecognisedOnDevnet(): void
    {
        putenv(ChargeFault::VARIABLE.'=fail-after-serving');
        self::assertSame(ChargeFault::FailAfterServing, ChargeFault::fromEnvironment(self::DEVNET));

        putenv(ChargeFault::VARIABLE.'=never-land');
        self::assertSame(ChargeFault::NeverLand, ChargeFault::fromEnvironment('https://devnet.helius-rpc.com/?api-key=x'));

        self::assertTrue(ChargeFault::FailAfterServing->skipsPreflight());
        self::assertTrue(ChargeFault::NeverLand->skipsPreflight());
    }

    public function testRefusedAnywhereButDevnet(): void
    {
        putenv(ChargeFault::VARIABLE.'=fail-after-serving');

        foreach (['https://api.mainnet-beta.solana.com', 'https://api.testnet.solana.com', 'https://example.com/devnet'] as $url) {
            try {
                ChargeFault::fromEnvironment($url);
                self::fail("accepted {$url}");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('not devnet', $e->getMessage(), $url);
            }
        }
    }

    public function testAWordItDoesNotKnowIsRefusedRatherThanIgnored(): void
    {
        putenv(ChargeFault::VARIABLE.'=fail');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a fault this site knows');
        ChargeFault::fromEnvironment(self::DEVNET);
    }

    /** Anything seen with a fault on says so, in the panel's first section. */
    public function testThePanelSaysWhenAFaultIsOn(): void
    {
        $inspector = new Inspector(Config::load(dirname(__DIR__, 2)));
        $deployment = static function () use ($inspector): array {
            foreach ($inspector->sections() as $section) {
                if ($section['heading'] === 'Deployment') {
                    return array_column($section['rows'] ?? [], 1, 0);
                }
            }

            return [];
        };

        self::assertArrayNotHasKey('test fault', $deployment());

        putenv(ChargeFault::VARIABLE.'=never-land');
        self::assertStringStartsWith('never-land — ', (string) ($deployment()['test fault'] ?? ''));
    }

    /**
     * The wiring, read rather than run: `Rpc` is final and talks to a real
     * endpoint, so there is nothing to stub. What matters is that the fault
     * reaches the send, and that only the article charge carries one.
     */
    public function testOnlyTheArticleChargeCarriesTheFaultToTheSend(): void
    {
        $root = dirname(__DIR__, 2);
        $submitter = (string) file_get_contents($root.'/src/Chain/Submitter.php');
        self::assertStringContainsString('->sendTransaction($wire, $fault->skipsPreflight())', $submitter);
        self::assertStringContainsString('$fault === ChargeFault::NeverLand', $submitter);

        $meter = (string) file_get_contents($root.'/src/Metering/Meter.php');
        self::assertSame(1, substr_count($meter, 'ChargeFault::fromEnvironment('), 'one place reads the variable');
        self::assertStringContainsString('$this->meter($wallet, $state, 1, false, ChargeFault::fromEnvironment(', $meter);
        self::assertStringContainsString('$this->meter($wallet, $state, $pageViews, true)', $meter, 'the advance sends as it always did');
    }
}
