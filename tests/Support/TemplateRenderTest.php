<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Metering\MeterOutcome;
use Newsprint\Metering\MeterResult;
use Newsprint\Support\View;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Blocked;
use SolPay\Core\Cause;
use SolPay\Core\Contract;
use SolPay\Core\Ids;
use SolPay\Core\Program;
use SolPay\Core\Shortfall;
use SolPay\Core\TokenAccount;

/**
 * Render every screen state with **every optional value populated**, and
 * treat any warning as a failure.
 *
 * Three bugs have reached the browser from this one blind spot, and all three
 * were the same mistake: assuming what a library's value object offers.
 * `Blocked` has no `->reason`; `Cause` has no `__toString()`. Reading a
 * missing property is a warning and a null, which renders as an empty string
 * and looks like a wording problem; casting an object with no `__toString()`
 * is a fatal, which takes the page down.
 *
 * Neither is visible to `php -l`, to the unit tests, or to a fixture that
 * leaves the optional value null — which is exactly what the scratch fixtures
 * did, because null is the easy thing to write. **So the rule here is that a
 * fixture must populate the awkward case, not the convenient one.** A panel
 * state that only ever renders with `cause: null` has not been rendered.

 * A static analyser would catch this class of thing directly and should be
 * added; until then, this is the cheap approximation.
 */
final class TemplateRenderTest extends TestCase
{
    private const SITE = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';
    private const MINT = 'AKRs35WnmePSDLcPLyVtC5UPPBC8xjmVZ4EUJ3XwU9op';
    private const PAYER = 'BFT5EZLV7eWhwX4jjRP7JJDuJYoCQRmvmzuDUBbvSMqR';
    private const CONTRACT = 'Fgm6costwpmn4d1CTqdM5su8jBptdwnW134cNoFixgqs';
    private const ATA = '3KDoatBW3VwL5tyKLnCrWreKSsAXEhymyCUpei6eLd7v';

    private function view(): View
    {
        return new View(dirname(__DIR__, 2).'/templates');
    }

    /**
     * Render, with every diagnostic promoted to an exception.
     *
     * An undefined array key or property is a warning, and a warning in a
     * template is a hole in the page that nobody notices in review.
     *
     * @param array<string, mixed> $vars
     */
    private function renderStrictly(string $template, array $vars): string
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message.' in '.basename($file).' line '.$line, 0, $severity, $file, $line);
        });

        try {
            return $this->view()->render($template, $vars);
        } finally {
            restore_error_handler();
        }
    }

    private function program(): Program
    {
        return new Program('F8UDAGgxVTm8Vmh4RmskpMBCFqhRvuTqbDxDCj8UMedL', Ids::TOKEN_PROGRAM_ID);
    }

    /** @return array<string, mixed> */
    private function panel(array $overrides = []): array
    {
        return $overrides + [
            'wallet' => self::PAYER,
            'stage' => 'metered',
            'symbol' => 'DEMO',
            'decimals' => 6,
            'balance' => '0.05',
            'limit_floor' => '0.5',
            'views_remaining' => 8,
            'blocked' => null,
            'contract' => ['address' => self::CONTRACT, 'limit' => '0.5', 'used' => '0.42', 'paid' => '0.28', 'unpaid' => '0.14'],
            'faucet' => ['demo' => '0.6', 'sol' => '0.05', 'available' => true],
            'provisioned' => true,
            'chain' => 'solana:devnet',
            'program' => $this->program()->id,
            'token_program' => $this->program()->tokenProgram,
            'result' => null,
            'page_price' => '0.01',
            'step_views' => 7,
            'advanced' => null,
            'solvency' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function solvency(int $balanceShort, int $allowanceShort, bool $delegate = true): array
    {
        $account = new TokenAccount(self::MINT, self::PAYER, 210000 - $balanceShort, $delegate ? self::CONTRACT : null, 210000 - $allowanceShort);
        $shortfall = Shortfall::diagnose($account, 210000);

        return [
            'would_move' => '0.21',
            'balance_short' => $shortfall->balanceShort,
            'balance_short_demo' => '0.0'.$balanceShort,
            'allowance_short' => $shortfall->allowanceShort,
            'allowance_short_demo' => '0.0'.$allowanceShort,
            'delegate_present' => $shortfall->delegatePresent,
            'clear' => $shortfall->isClear(),
        ];
    }

    /**
     * The failure panel, with a real `Cause` of each kind.
     *
     * This is the case that took the site down: the fixture had been passing
     * `cause: null`, which is the one value that does not exercise the line
     * that renders it.
     */
    public function testTheFailurePanelRendersWithEveryKindOfCause(): void
    {
        $program = $this->program();
        $account = new TokenAccount(self::MINT, self::PAYER, 1000, self::CONTRACT, 500000);
        $shortfall = Shortfall::diagnose($account, 210000);

        $causes = [
            'this program' => Cause::of($program, $program->id, 6003),
            'SPL Token' => Cause::of($program, Ids::TOKEN_PROGRAM_ID, 1),
            'something else' => Cause::of($program, '11111111111111111111111111111111', 42),
            'none at all' => null,
        ];

        foreach ($causes as $label => $cause) {
            $result = MeterResult::failed('the transfer was refused', $cause, $shortfall, null);
            $html = $this->renderStrictly('meter', [
                'meter' => $this->panel(['stage' => 'failed', 'result' => $result]),
                'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
            ]);

            self::assertStringContainsString('did not go through', $html, $label);
        }
    }

    /** `Blocked` is the other value object this site has guessed at. */
    public function testTheLimitPanelRendersWithARealBlocked(): void
    {
        $html = $this->renderStrictly('meter', [
            'meter' => $this->panel([
                'stage' => 'limit',
                'blocked' => (string) Blocked::limitReached(1000),
                'result' => MeterResult::blocked(Blocked::limitReached(1000), 70000),
            ]),
            'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
        ]);

        self::assertStringContainsString('limit', $html);
    }

    public function testEveryPanelStageRenders(): void
    {
        $stages = ['anonymous', 'unfunded', 'set-meter', 'metered', 'limit', 'unreadable'];

        foreach ($stages as $stage) {
            $html = $this->renderStrictly('meter', [
                'meter' => $this->panel(['stage' => $stage, 'blocked' => (string) Blocked::limitReached(1000)]),
                'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
            ]);

            self::assertNotSame('', trim($html), $stage);
        }

        $unprovisioned = $this->renderStrictly('meter', [
            'meter' => $this->panel(['provisioned' => false]),
            'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
        ]);
        self::assertStringContainsString('set up', $unprovisioned);
    }

    public function testTheStripRendersEveryAdvanceOutcomeAndEveryWarning(): void
    {
        $piece = new \Newsprint\Content\Piece('two-orderings', 'T', 'L', 4, true, 'draft', '');

        $outcomes = [
            MeterOutcome::Metered,
            MeterOutcome::Unconfirmed,
            MeterOutcome::Granted,
            MeterOutcome::Blocked,
            MeterOutcome::Failed,
            MeterOutcome::Unreadable,
        ];

        foreach ($outcomes as $outcome) {
            foreach ([$this->solvency(80000, 0), $this->solvency(0, 50000), $this->solvency(0, 0)] as $solvency) {
                $meter = $this->panel([
                    'result' => MeterResult::granted(),
                    'solvency' => $solvency,
                    'advanced' => ['outcome' => $outcome, 'views' => 7, 'signature' => null, 'settled' => false],
                ]);

                $html = $this->renderStrictly('meter-strip', ['result' => $meter['result'], 'meter' => $meter, 'piece' => $piece]);
                self::assertNotSame('', trim($html), $outcome->value);
            }
        }

        // And the pre-emptive warning, which only renders when nothing has
        // been advanced yet.
        $meter = $this->panel(['result' => MeterResult::granted(), 'solvency' => $this->solvency(80000, 0)]);
        $html = $this->renderStrictly('meter-strip', ['result' => $meter['result'], 'meter' => $meter, 'piece' => $piece]);
        self::assertStringContainsString('Heads up', $html);
    }

    public function testManageMeterRendersInEveryState(): void
    {
        $common = [
            'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
            'wallet' => self::PAYER,
            'chain' => 'solana:devnet',
            'program' => $this->program()->id,
            'token_program' => $this->program()->tokenProgram,
            'symbol' => 'DEMO',
            'contract' => ['address' => self::CONTRACT, 'limit' => '0.5', 'used' => '0.42', 'paid' => '0.28', 'unpaid' => '0.14'],
            'views_remaining' => 8,
            'blocked' => (string) Blocked::limitReached(1000),
            'limit_floor' => '0.5',
            'balance' => '0.05',
            'live_grants' => 2,
            'token_account' => self::ATA,
            'delegate' => self::CONTRACT,
            'approved' => '0.5',
        ];

        foreach (['anonymous', 'unreadable', 'no-contract', 'open'] as $stage) {
            $html = $this->renderStrictly('manage-meter', ['stage' => $stage] + $common);
            self::assertNotSame('', trim($html), $stage);
        }

        // A closed contract: no delegate left, which is claim 6's own case.
        $html = $this->renderStrictly('manage-meter', ['stage' => 'open', 'delegate' => null, 'approved' => '0'] + $common);
        self::assertStringContainsString('none', $html);
    }

    public function testAContractDecodesIntoThePanelWithoutGuessingAtItsShape(): void
    {
        // Not a render: a reminder that `Contract` is a value object with
        // named fields and a derived `unpaid()`, and that reading anything
        // else off it is the mistake this whole file exists for.
        $contract = new Contract(self::SITE, self::PAYER, 500000, 420000, 280000, 255);

        self::assertSame(140000, $contract->unpaid());
    }
}
