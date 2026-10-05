<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Metering\ChargeState;
use Newsprint\Metering\MeterOutcome;
use Newsprint\Metering\MeterResult;
use Newsprint\Support\View;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Blocked;
use SolPay\Core\Cause;
use SolPay\Core\Ids;
use SolPay\Core\Meter;
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
    private const FUND = 'BFT5EZLV7eWhwX4jjRP7JJDuJYoCQRmvmzuDUBbvSMqR';
    private const METER = 'Fgm6costwpmn4d1CTqdM5su8jBptdwnW134cNoFixgqs';
    private const KEY = 'D5VoE7hnS4yDaaMcEfgwcPyMnwNG5Xb2j4WFFFtHKwKq';

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
    /**
     * *Your wallet* carries what a wallet sends in an inert element
     * (2026-10-04), and three files have to agree for a reader to see one of
     * those rows: the template writes them, `key.js` leaves the kind for the
     * next page, and `inspector.js` moves the matching row. A `<template>`
     * element renders nothing, so a disagreement shows as a row that never
     * appears and as no error at all.
     */
    public function testWhatTheWalletSentIsInertUntilTheBrowserSaysSo(): void
    {
        $html = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'Your wallet',
            'claims' => 'under',
            'rows' => [['wallet', 'not known']],
            'sent' => ['renew' => 'a renewal of this meter, before this page loaded'],
        ]]]);

        self::assertMatchesRegularExpression(
            '~</table>\s*<template data-wallet-sent>\s*<tr data-sent="renew">\s*<th scope="row">sent</th>\s*<td>a renewal of this meter, before this page loaded</td>\s*</tr>\s*</template>~',
            $html,
            'the row waits in a template element, after the table it will join',
        );
        self::assertSame(1, substr_count($html, 'a renewal of this meter'), 'and nowhere a reader can see it yet');

        $plain = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'Treasury',
            'rows' => [['balance', '1']],
        ]]]);
        self::assertStringNotContainsString('<template', $plain);

        $root = dirname(__DIR__, 2);
        $key = (string) file_get_contents($root.'/public/assets/key.js');
        $inspector = (string) file_get_contents($root.'/public/assets/inspector.js');

        self::assertStringContainsString("const SENT = 'newsprint:sent';", $key);
        self::assertStringContainsString("const SENT = 'newsprint:sent';", $inspector, 'one name for the word the two scripts pass');
        self::assertStringContainsString('rememberSent(pending.kind);', $key, 'left on *continue*, before the page goes');
        self::assertStringContainsString("'template[data-wallet-sent]'", $inspector);
        self::assertStringContainsString("'[data-sent]'", $inspector);
        self::assertStringContainsString('sessionStorage.removeItem(SENT)', $inspector, 'read once: the row lasts one page');
    }

    /**
     * SPEC §9.2: the close's request renders the panel, and the page puts it
     * where the old one was. The answer carries the sections only, so the
     * page fills the body it has and tells `inspector.js` to look again.
     */
    public function testTheClosePutsItsPanelWhereTheOldOneWas(): void
    {
        $root = dirname(__DIR__, 2);
        $key = (string) file_get_contents($root.'/public/assets/key.js');
        $front = (string) file_get_contents($root.'/public/index.php');

        self::assertStringContainsString('showPanel(sent.body.panel);', $key);
        self::assertStringContainsString("querySelector('details.inspector .inspector-body')", $key);
        self::assertStringContainsString("new CustomEvent('newsprint:inspector')", $key);
        self::assertSame(2, substr_count($front, "'panel' => \$sections,"), 'both answers carry it: closed, and sent but not landed');
    }

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

    /** @return array<string, string> */
    private function site(): array
    {
        return ['symbol' => 'DEMO', 'page_price_demo' => '0.01'];
    }

    /** @return array<string, mixed> */
    private function panel(array $overrides = []): array
    {
        return $overrides + [
            'stage' => 'metered',
            'ended' => false,
            'symbol' => 'DEMO',
            'decimals' => 6,
            'balance' => '0.05',
            'limit_floor' => '0.5',
            'items_remaining' => 8,
            'blocked' => null,
            'meter' => ['address' => self::METER, 'limit' => '0.5', 'used' => '0.42', 'paid' => '0.28', 'unpaid' => '0.14', 'expiry' => '2026-10-02 12:00 UTC'],
            'provisioned' => true,
            'result' => null,
            'page_price' => '0.01',
            'step_views' => 7,
            'advanced' => null,
            'solvency' => null,
            'live_grants' => 2,
            'setup' => ['limit' => '0.5', 'deposit' => '0.5', 'expiries' => ['hour', 'day', 'week', 'month'], 'dev_wallet' => false],
        ];
    }

    /**
     * The heads-up's inputs, worked out the way `$meterVars` works them out:
     * through `Shortfall::of`, from a token account short by `$short`.
     *
     * @return array<string, mixed>
     */
    private function solvency(int $short): array
    {
        $account = new TokenAccount(self::MINT, self::FUND, 210000 - $short, null, 0);
        $shortfall = Shortfall::of($account, 210000);

        return [
            'would_move' => '0.21',
            'short' => $shortfall,
            'short_demo' => '0.0'.$short,
            'clear' => $shortfall === 0,
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
        $shortfall = Shortfall::of(new TokenAccount(self::MINT, self::FUND, 1000, null, 0), 210000);

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
            self::assertStringContainsString('Your fund is short by 0.209 DEMO', self::said($html), $label.': §8.2, one number and the answer is money');
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
        $stages = ['anonymous', 'metered', 'limit', 'expired', 'failed', 'unreadable'];

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
        $piece = new \Newsprint\Content\Piece('two-orderings', 'T', 'L', 4, true, 'draft', '2026-09-07', null, '');

        $outcomes = [
            MeterOutcome::Metered,
            MeterOutcome::Unconfirmed,
            MeterOutcome::Granted,
            MeterOutcome::Blocked,
            MeterOutcome::Failed,
            MeterOutcome::Unreadable,
        ];

        foreach ($outcomes as $outcome) {
            foreach ([$this->solvency(80000), $this->solvency(0)] as $solvency) {
                $meter = $this->panel([
                    'result' => MeterResult::granted(),
                    'solvency' => $solvency,
                    'advanced' => ['outcome' => $outcome, 'views' => 7, 'signature' => null, 'settled' => false],
                ]);

                $html = $this->renderStrictly('meter-strip', ['result' => $meter['result'], 'meter' => $meter, 'piece' => $piece]);
                self::assertNotSame('', trim($html), $outcome->value);
            }
        }

        // And the pre-emptive warning.
        $meter = $this->panel(['result' => MeterResult::granted(), 'solvency' => $this->solvency(80000)]);
        $html = $this->renderStrictly('meter-strip', ['result' => $meter['result'], 'meter' => $meter, 'piece' => $piece]);
        self::assertStringContainsString('Heads up: the next advance would try to move', $html);
    }

    /**
     * The warning survives the click that makes it true.
     *
     * `can_meter` checks the expiry and the limit, so the only thing that
     * knows a fund is short is the solvency read this page already does — and the advance that
     * spends the balance down is exactly the one after which the next click is
     * certain to fail. The gate used to be "has anything been advanced", which
     * silenced the warning for the rest of the visit on the first success: the
     * one case it exists for was the one case that did not get it.
     *
     * Both halves are asserted, because a gate that never suppresses is as
     * wrong as one that always does. A *failed* advance has already named the
     * same shortfall in the same terms, and must not say it twice.
     *
     * **On the negative assertion.** `assertStringNotContainsString` on a
     * phrase can start passing by looking for a string nothing could contain,
     * which is not a test. It is sound here only because the positive
     * assertions above it look for the same phrase: reword the warning and
     * they go red together, which is the signal to update both.
     */
    public function testTheHeadsUpIsSuppressedOnlyWhenTheAdvanceAlreadySaidIt(): void
    {
        $piece = new \Newsprint\Content\Piece('two-orderings', 'T', 'L', 4, true, 'draft', '2026-09-07', null, '');
        $warning = 'Heads up: the next advance would try to move';

        $strip = function (MeterOutcome $outcome, bool $settled) use ($piece): string {
            $meter = $this->panel([
                'result' => MeterResult::granted(),
                'solvency' => $this->solvency(80000),
                'advanced' => ['outcome' => $outcome, 'views' => 7, 'signature' => null, 'settled' => $settled],
            ]);

            return $this->renderStrictly('meter-strip', ['result' => $meter['result'], 'meter' => $meter, 'piece' => $piece]);
        };

        // Every outcome that said nothing about solvency still warns — the
        // settle that went through included, which is the repair.
        self::assertStringContainsString($warning, $strip(MeterOutcome::Metered, true), 'a settle that left the reader short');
        self::assertStringContainsString($warning, $strip(MeterOutcome::Metered, false), 'an advance that moved nothing');
        self::assertStringContainsString($warning, $strip(MeterOutcome::Blocked, false), 'a limit refusal is a different problem');
        self::assertStringContainsString($warning, $strip(MeterOutcome::Unreadable, false), 'nothing was sent, so nothing was learned');

        // And the one that did say it does not repeat itself.
        $failed = $strip(MeterOutcome::Failed, false);
        self::assertStringNotContainsString($warning, $failed, 'the failure report already named this');
        self::assertStringContainsString('Your fund is short by', $failed, 'and it is still the failure report that names it');
    }

    /**
     * The panel for a browser with no session. A browser that holds a key and
     * a meter binds from here, so the key's script is loaded and has a line to
     * speak on. The setup form is hidden until the script arrives, because
     * without it the key the setup names cannot be made. And when the read
     * has just ended a session, the panel says why the meter is gone from
     * this browser (SPEC §5.3).
     */
    public function testTheAnonymousPanelBindsAHeldKeyOrSetsUp(): void
    {
        $plain = $this->renderStrictly('meter', [
            'meter' => $this->panel(['stage' => 'anonymous', 'meter' => null, 'items_remaining' => null]),
            'site' => $this->site(),
        ]);
        self::assertStringNotContainsString('session with it has ended', self::said($plain));
        self::assertMatchesRegularExpression('/<form class="setup" data-setup-form data-kind="setup" hidden>/', $plain, 'nothing to press until the script can act on it');
        self::assertMatchesRegularExpression('/<p class="pending" data-key-bind role="status" hidden><\/p>/', $plain);
        self::assertStringContainsString('<script type="module" src="/assets/key.js"></script>', $plain);

        $ended = self::said($this->renderStrictly('meter', [
            'meter' => $this->panel(['stage' => 'anonymous', 'ended' => true, 'meter' => null, 'items_remaining' => null]),
            'site' => $this->site(),
        ]));
        self::assertStringContainsString('renewed from another device, which then holds it', $ended);

        // A session's stages have no key work to do on the article.
        self::assertStringNotContainsString('key.js', $this->renderStrictly('meter', ['meter' => $this->panel(), 'site' => $this->site()]));
    }

    /**
     * `set_meter` (SPEC §6.3): the four answers, the scan they start, and
     * *continue*. What the script looks for is what the template renders, and
     * the form's fields are the names `POST /meter/setup` reads.
     */
    public function testTheSetupFormRendersWhatKeyJsLooksFor(): void
    {
        $html = $this->renderStrictly('meter', [
            'meter' => $this->panel(['stage' => 'anonymous', 'meter' => null, 'items_remaining' => null]),
            'site' => $this->site(),
        ]);
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/key.js');

        foreach (['data-setup-form', 'data-setup-scan', 'data-setup-link', 'data-setup-qr', 'data-setup-continue', 'data-setup-status', 'data-key-bind'] as $hook) {
            self::assertStringContainsString($hook, $html, $hook.' in the template');
            self::assertStringContainsString('['.$hook.']', $script, $hook.' in key.js');
        }
        foreach (['limit', 'deposit', 'index'] as $field) {
            self::assertMatchesRegularExpression('/<input[^>]*name="'.$field.'"/', $html, $field.' is read by name');
        }
        foreach (['hour', 'day', 'week', 'month'] as $choice) {
            self::assertMatchesRegularExpression('/<input type="radio" name="expiry" value="'.$choice.'"/', $html, 'expiry '.$choice);
        }
        self::assertStringContainsString('data-kind="setup"', $html);
        self::assertStringContainsString('key.js', $html);

        // §12.6: the development wallet only where it is on, and labelled.
        self::assertStringNotContainsString('data-setup-development', $html);
        $development = $this->renderStrictly('meter', [
            'meter' => $this->panel(['stage' => 'anonymous', 'meter' => null, 'items_remaining' => null,
                'setup' => ['limit' => '0.5', 'deposit' => '0.5', 'expiries' => [], 'dev_wallet' => true]]),
            'site' => $this->site(),
        ]);
        self::assertStringContainsString('data-setup-development', $development);
        self::assertStringContainsString('Development stand-in', $development);
        self::assertStringContainsString('[data-setup-development]', $script);
    }

    /** `manage_meter` starts the same scan for renewing and for adding to the fund (§6.4). */
    public function testTheMeterPageOffersRenewAndAddToTheFund(): void
    {
        $html = $this->renderStrictly('manage-meter', ['stage' => 'open', 'site' => $this->site()] + $this->panel());

        self::assertStringContainsString('data-kind="renew"', $html);
        self::assertStringContainsString('data-kind="deposit"', $html);
        self::assertStringContainsString('id="renew"', $html);
        self::assertSame(1, substr_count($html, 'data-setup-scan'), 'one scan region serves both forms');

        // SPEC §6.4: *continue* returns a reader to the article they came
        // from. The line that says so is hidden until `key.js` knows of one.
        self::assertMatchesRegularExpression('/<p class="fine" data-back hidden>/', $html);
        self::assertStringContainsString('[data-back]', (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/key.js'));
        self::assertStringContainsString('folds in the 0.14 you are carrying', self::said($html));
    }

    /**
     * SPEC §4.3: nothing is behind a click. Before the form is submitted the
     * page says what is sent, that it is once per address, that it is worth
     * nothing, and that no real site would offer it. The form is a plain POST.
     */
    public function testTheFaucetSaysWhatItSendsBeforeItIsAsked(): void
    {
        $vars = ['site' => $this->site(), 'demo' => '0.6', 'sol' => '0.01', 'provisioned' => true, 'hosted' => false, 'address' => '', 'result' => null, 'development' => null];
        $html = $this->renderStrictly('faucet', $vars);
        $said = self::said($html);

        foreach (['0.6 DEMO', '0.01 SOL', 'Once per address', 'worth nothing', 'No real site would have one'] as $claim) {
            self::assertStringContainsString($claim, $said, $claim);
        }
        self::assertMatchesRegularExpression('/<form method="post" action="\/faucet"/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*name="address"/', $html);
        self::assertSame(1, substr_count($html, '<button'), 'a single button sends it');
        self::assertStringNotContainsString('<script', $html, 'no script: the form is the whole of it');
        self::assertStringNotContainsString('data-faucet-development', $html);

        $refused = self::said($this->renderStrictly('faucet', ['address' => 'PAYRfig', 'result' => [
            'granted' => false, 'reason' => 'x', 'message' => 'this wallet has already had its one grant', 'signature' => null,
        ]] + $vars));
        self::assertStringContainsString('Nothing was sent: this wallet has already had its one grant.', $refused);
        self::assertStringContainsString('value="PAYRfig"', $refused, 'what was pasted is still in the field');

        $granted = self::said($this->renderStrictly('faucet', ['address' => 'PAYRfig', 'result' => [
            'granted' => true, 'reason' => 'confirmed', 'message' => 'confirmed', 'signature' => 'SIGfig',
        ]] + $vars));
        self::assertStringContainsString('are on their way to <code>PAYRfig</code>', $granted);
        self::assertStringContainsString('explorer.solana.com/tx/SIGfig?cluster=devnet', $granted);
        self::assertStringNotContainsString('<form', $granted, 'once per address, so no second form');

        $development = $this->renderStrictly('faucet', ['development' => 'DEVWfig'] + $vars);
        self::assertStringContainsString('Development stand-in', $development);
        self::assertStringContainsString('DEVWfig', $development);

        self::assertStringContainsString('has not been set up yet', self::said($this->renderStrictly('faucet', ['provisioned' => false] + $vars)));

        // SPEC §12.6: the hosted instance may be taken down, and says so
        // before the reader commits anything. A local copy does not say it,
        // because there it is not true.
        self::assertStringNotContainsString('data-faucet-temporary', $html);
        $hosted = self::said($this->renderStrictly('faucet', ['hosted' => true] + $vars));
        self::assertStringContainsString('This copy of the site is temporary', $hosted);
        self::assertStringContainsString('Close your meter when you finish reading', $hosted);
    }

    /**
     * SPEC §12.0: setup is `bin/setup`. The page left at `/setup` names the
     * command and offers nothing to press, in either state.
     */
    public function testTheSetupPageNamesTheCommandAndHasNoButton(): void
    {
        $missing = $this->renderStrictly('setup', ['provisioned' => false, 'site' => $this->site() + ['decimals' => 6]]);
        self::assertStringContainsString('<code>bin/setup</code>', $missing);
        self::assertStringContainsString('initialize_site', $missing);

        $done = $this->renderStrictly('setup', ['provisioned' => true, 'site' => $this->site() + ['decimals' => 6]]);
        self::assertStringContainsString('is set up', self::said($done));
        self::assertStringNotContainsString('bin/setup', $done);

        foreach ([$missing, $done] as $html) {
            self::assertStringNotContainsString('<form', $html);
            self::assertStringNotContainsString('<button', $html);
        }
    }

    /** §8.2's `Expired`, which the delegate design never had. */
    public function testAnExpiredMeterSaysWhenItExpired(): void
    {
        $html = self::said($this->renderStrictly('meter', [
            'meter' => $this->panel(['stage' => 'expired', 'blocked' => (string) Blocked::expired()]),
            'site' => $this->site(),
        ]));

        self::assertStringContainsString('Your meter has expired', $html);
        self::assertStringContainsString('2026-10-02 12:00 UTC', $html);
    }

    /**
     * The advance's progress sentence, and the one assumption `advance.js`
     * makes about the markup: that the server never renders the button
     * disabled or the sentence shown. The script resets both on arrival, which
     * is only safe if neither can be the server's own state.
     */
    public function testTheAdvanceFormCarriesItsProgressSentenceHidden(): void
    {
        $piece = new \Newsprint\Content\Piece('two-orderings', 'T', 'L', 4, true, 'draft', '2026-09-07', null, '');
        $meter = $this->panel(['result' => MeterResult::granted()]);
        $html = $this->renderStrictly('meter-strip', ['result' => $meter['result'], 'meter' => $meter, 'piece' => $piece]);

        self::assertMatchesRegularExpression('/<form[^>]*\bdata-advance\b/', $html);
        self::assertMatchesRegularExpression('/<p[^>]*\bdata-advance-status\b[^>]*\brole="status"[^>]*\bhidden\b[^>]*>\s*Advancing the meter 7 views…/u', $html);
        self::assertStringContainsString('<script type="module" src="/assets/advance.js"></script>', $html);
        self::assertFileExists(dirname(__DIR__, 2).'/public/assets/advance.js');
        self::assertDoesNotMatchRegularExpression('/<button[^>]*\bdisabled\b/', $html);
    }

    /**
     * `advance.js` removes the advance's report from the address once it has
     * been shown, so a refresh or a back cannot replay an old advance as a new
     * one. A key the route writes and the script does not remove would leave
     * half a report in the URL — `?tx=` alone, say — so the three lists are
     * held together: what `/meter/advance` writes, what the article route
     * reads, and what the script removes.
     */
    public function testAdvanceJsRemovesExactlyTheKeysTheAdvanceRouteWrites(): void
    {
        $root = dirname(__DIR__, 2);
        $index = (string) file_get_contents($root.'/public/index.php');
        $script = (string) file_get_contents($root.'/public/assets/advance.js');

        $start = strpos($index, "\$app->post('/meter/advance'");
        self::assertNotFalse($start, 'the advance route');
        $end = strpos($index, "\n});", $start);
        self::assertNotFalse($end);
        preg_match_all('/[\'"][?&]([a-z_]+)=/', substr($index, $start, $end - $start), $written);

        preg_match_all('/\$query\[\'([a-z_]+)\'\]/', $index, $read);

        self::assertSame(1, preg_match('/ADVANCE_KEYS = \[([^\]]*)\]/', $script, $list));
        preg_match_all('/\'([a-z_]+)\'/', $list[1], $removed);

        $sorted = static function (array $keys): array {
            $keys = array_values(array_unique($keys));
            sort($keys);

            return $keys;
        };

        self::assertNotSame([], $written[1], 'a scanner that found nothing proves nothing');
        self::assertSame($sorted($written[1]), $sorted($removed[1]), 'written by /meter/advance vs removed by advance.js');
        self::assertSame($sorted($written[1]), $sorted($read[1]), 'written by /meter/advance vs read by the article route');
    }

    /**
     * The article shell (2026-09-11), which `GET /a/{slug}` sends without a
     * chain read and `assets/read-on.js` posts at once.
     *
     * Three things are held here. The form posts to the article's own URL, so
     * the no-JS path is the same request as the scripted one. The script's
     * reset is safe for the advance's reason: the server never renders the
     * button disabled or a line shown. And the shell carries **nothing a
     * charge can change** — no body, no meter, no price — which is what lets
     * the POST's answer replace it rather than reload it.
     *
     * **The waiting copy is not asserted, on purpose.** It is Gato's to
     * reword, and a test quoting it turns every edit of a sentence into an
     * edit of a test — which is what happened the first time it was reworded.
     * What matters to the script is the shape: which line is visible when the
     * region appears, which is held back and for how long, which is empty
     * until a failure. So the shape is read out of the markup, and the words
     * only have to be there.
     */
    public function testTheShellPostsToItsOwnUrlAndClaimsNothingTheChainWouldAnswer(): void
    {
        $piece = new \Newsprint\Content\Piece('two orderings', 'T', 'The lede.', 4, true, 'draft', '2026-09-07', null, '');
        $html = $this->renderStrictly('article-pending', ['piece' => $piece, 'longWaitMs' => 7000]);

        self::assertMatchesRegularExpression('/<form method="post" action="\/a\/two%20orderings"[^>]*\bdata-read-on\b/', $html);
        self::assertDoesNotMatchRegularExpression('/<button[^>]*\bdisabled\b/', $html);
        self::assertStringContainsString('<script type="module" src="/assets/read-on.js"></script>', $html);
        self::assertFileExists(dirname(__DIR__, 2).'/public/assets/read-on.js');
        self::assertStringContainsString('The lede.', $html);

        $shell = $this->elements($html);

        // One live region, hidden: without JavaScript nothing is under way.
        $status = $shell['data-read-on-status'] ?? null;
        self::assertNotNull($status, 'the live region');
        self::assertSame('status', $status->getAttribute('role'));
        self::assertTrue($status->hasAttribute('hidden'), 'the region is hidden until the POST goes out');

        // The first line shows with the region; the second is held back until
        // the wait is longer than usual, and carries the threshold the script
        // reads; the third is empty, because only the script knows a reason.
        $now = $shell['data-read-on-now'] ?? null;
        self::assertNotNull($now, 'the first line');
        self::assertFalse($now->hasAttribute('hidden'));
        self::assertNotSame('', trim($now->textContent), 'the first line says something');

        $later = $shell['data-read-on-later'] ?? null;
        self::assertNotNull($later, 'the second line');
        self::assertTrue($later->hasAttribute('hidden'));
        self::assertSame('7000', $later->getAttribute('data-after-ms'));
        self::assertNotSame('', trim($later->textContent), 'the second line says something');

        $failed = $shell['data-read-on-failed'] ?? null;
        self::assertNotNull($failed, 'the failure line');
        self::assertTrue($failed->hasAttribute('hidden'));
        self::assertSame('', trim($failed->textContent));

        // All three are inside the one live region, so the second line and
        // the failure are announced when they appear rather than arriving
        // silently.
        self::assertSame($status, $now->parentNode);
        self::assertSame($status, $later->parentNode);
        self::assertSame($status, $failed->parentNode);

        // Nothing a charge can change.
        self::assertStringNotContainsString('class="body"', $html);
        self::assertStringNotContainsString('data-meter', $html);
        self::assertStringNotContainsString('DEMO', $html);
    }

    /** Rendered text with its wrapping collapsed, so a line break cannot hide a sentence. */
    private static function said(string $html): string
    {
        return (string) preg_replace('/\s+/u', ' ', $html);
    }

    /**
     * The elements carrying a `data-read-on…` attribute, by that attribute.
     *
     * `ext-dom` rather than a regular expression: the questions above are
     * about which element is hidden and what is inside which, and an attribute
     * moved to the next line should not be able to fail a test about that.
     * PHPUnit requires the extension, so anywhere this suite runs, it is there.
     *
     * @return array<string, \DOMElement>
     */
    private function elements(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NOERROR);
        libxml_use_internal_errors($previous);

        $found = [];
        foreach ((new \DOMXPath($document))->query('//*') as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            foreach ($element->attributes as $attribute) {
                if (str_starts_with($attribute->nodeName, 'data-read-on')) {
                    $found[$attribute->nodeName] = $element;
                }
            }
        }

        return $found;
    }

    /**
     * What the scripts look for, against what the templates render.
     *
     * `read-on.js` finds the shell by three data attributes; `swap.js` takes
     * two parts out of the answer — `article.piece` and `.inspector-body` —
     * and both the shell's POST and the advance's go through it. Rename any of
     * them on one side only and the page sits on its waiting line for good,
     * having charged, with no error anywhere: the fragment arrives, the
     * selector finds nothing, and the body never lands. So the sides are read
     * and compared.
     */
    public function testTheScriptsLookForWhatTheTemplatesRender(): void
    {
        $root = dirname(__DIR__, 2);
        $shell = (string) file_get_contents($root.'/templates/article-pending.php');
        $strip = (string) file_get_contents($root.'/templates/meter-strip.php');
        $readOn = (string) file_get_contents($root.'/public/assets/read-on.js');
        $advance = (string) file_get_contents($root.'/public/assets/advance.js');
        $swap = (string) file_get_contents($root.'/public/assets/swap.js');

        // Each script's own controls, in the template that renders them.
        foreach ([[$readOn, $shell, 'data-read-on'], [$advance, $strip, 'data-advance']] as [$script, $template, $prefix]) {
            preg_match_all('/\['.$prefix.'([a-z-]*)\]/', $script, $sought);
            self::assertNotSame([], $sought[1], $prefix.': a scanner that found nothing proves nothing');
            foreach (array_unique($sought[1]) as $suffix) {
                self::assertMatchesRegularExpression('/\s'.preg_quote($prefix.$suffix, '/').'[\s>]/', $template, $prefix.$suffix);
            }
        }

        // Both scripts hand their POST to the same swap, and what it puts back
        // is what the answer is made of.
        foreach ([$readOn, $advance] as $script) {
            self::assertStringContainsString("import { swap } from './swap.js'", $script);
        }
        self::assertStringContainsString("'X-Fragment': '1'", $swap);
        self::assertStringContainsString("querySelector('article.piece')", $swap);
        self::assertStringContainsString("querySelector('.inspector-body')", $swap);

        // The article is the shell's replacement and the advance's, so all
        // three are the same element.
        self::assertStringContainsString('<article class="piece">', $shell);
        self::assertStringContainsString('<article class="piece">', (string) file_get_contents($root.'/templates/article.php'));
        self::assertStringContainsString('class="inspector-body"', (string) file_get_contents($root.'/templates/inspector.php'));

        // The threshold travels as `data-after-ms`, read as `dataset.afterMs`.
        self::assertStringContainsString('data-after-ms=', $shell);
        self::assertStringContainsString('dataset.afterMs', $readOn);

        // **A module runs once per document, and the advance's answer carries
        // the next advance's form.** Binding to the form at load worked on the
        // first answer and was dead on the second, which fell back to a
        // full-page form submit — seen in the capture of 2026-09-11 22:22.
        // So the advance listens from the document, and the swap does not
        // pretend that re-inserting a module re-runs it.
        self::assertStringContainsString("document.addEventListener('submit'", $advance);
        self::assertStringNotContainsString('form.addEventListener', $advance);
        self::assertStringContainsString('script[type="module"][src]', $swap);
    }

    /**
     * **The way out is on every screen that holds a session** (§6).
     *
     * It used to sit in the panel's last branch, which the trace of 2026-09-09
     * found is reachable only on a prefetch — so the "permanent" link had
     * almost certainly never been on a screen. The condition is a session's
     * meter, or a session whose meter could not be read.
     */
    public function testEveryStageHoldingASessionOffersTheWayOut(): void
    {
        $shortfall = Shortfall::of(new TokenAccount(self::MINT, self::FUND, 1000, null, 0), 210000);

        foreach (['failed', 'limit', 'expired', 'metered'] as $stage) {
            $html = self::said($this->renderStrictly('meter', [
                'meter' => $this->panel([
                    'stage' => $stage,
                    'blocked' => (string) Blocked::limitReached(1000),
                    'result' => MeterResult::failed('refused', null, $shortfall, null),
                ]),
                'site' => $this->site(),
            ]));
            // One link each. A short fund's is the offer itself (§8.2): the
            // answer is money, so the link goes to where money is added.
            self::assertSame(1, substr_count($html, 'href="/meter'), $stage);
            self::assertStringContainsString($stage === 'failed' ? 'href="/meter#add">Add to the fund' : 'what you have spent', $html, $stage);
        }

        $unreadable = self::said($this->renderStrictly('meter', [
            'meter' => $this->panel(['stage' => 'unreadable', 'meter' => null, 'items_remaining' => null]),
            'site' => $this->site(),
        ]));
        self::assertStringContainsString('href="/meter"', $unreadable);

        // No session, nothing to manage.
        $anonymous = self::said($this->renderStrictly('meter', [
            'meter' => $this->panel(['stage' => 'anonymous', 'meter' => null, 'items_remaining' => null]),
            'site' => $this->site(),
        ]));
        self::assertStringNotContainsString('href="/meter"', $anonymous);
    }

    public function testManageMeterRendersInEveryState(): void
    {
        $common = ['site' => $this->site()] + $this->panel(['blocked' => (string) Blocked::expired()]);

        foreach (['anonymous', 'unreadable', 'open'] as $stage) {
            $html = $this->renderStrictly('manage-meter', ['stage' => $stage] + $common);
            self::assertNotSame('', trim($html), $stage);
        }

        $open = self::said($this->renderStrictly('manage-meter', ['stage' => 'open'] + $common));
        self::assertStringContainsString('2026-10-02 12:00 UTC', $open, 'the expiry is on the page');
        self::assertStringContainsString('Renew the meter', $open, 'and the way to renew it');

        $ended = self::said($this->renderStrictly('manage-meter', ['stage' => 'anonymous', 'ended' => true] + $common));
        self::assertStringContainsString('session with it has ended', $ended);
        self::assertStringContainsString('data-key-bind', $ended, 'a browser holding a key binds from this page too');
    }

    /**
     * SPEC §5.4 and §10.4: the close, with its disclosures **before** the
     * button. Each could change the decision, so each is asserted to come
     * first: the residue forgiven, the grants lost and how many, the faucet
     * row that survives, and the line the chain gains.
     */
    public function testTheCloseSaysWhatItCostsBeforeTheButton(): void
    {
        $html = $this->renderStrictly('manage-meter', ['stage' => 'open', 'site' => $this->site()] + $this->panel(['live_grants' => 3]));
        $said = self::said($html);

        $button = strpos($html, 'data-close-meter');
        self::assertIsInt($button);
        foreach ([
            'is <strong>forgiven</strong>',
            '3 live right now',
            '<strong>Those articles stop being served</strong>',
            'faucet ledger row survives</strong>',
            'Closed { meter, forgiven }',
        ] as $disclosure) {
            $at = strpos($said, $disclosure);
            self::assertIsInt($at, $disclosure);
            self::assertLessThan(strpos($said, 'data-close-meter'), $at, $disclosure.' comes before the button');
        }

        // Hidden until the script runs, because the key is the script's.
        self::assertMatchesRegularExpression('/<button[^>]*data-close-meter[^>]*hidden/', $html);
        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString('<script type="module" src="/assets/key.js"></script>', $html);

        $none = self::said($this->renderStrictly('manage-meter', ['stage' => 'open', 'site' => $this->site()] + $this->panel(['live_grants' => 0])));
        self::assertStringContainsString('none are live right now', $none);

        // The panel on the article links to it once, on each blocked stage:
        // `/meter` is the place to renew, add or close from (2026-10-01).
        foreach (['limit', 'expired'] as $stage) {
            $panel = $this->renderStrictly('meter', ['meter' => $this->panel(['stage' => $stage]), 'site' => $this->site()]);
            self::assertSame(1, substr_count($panel, 'href="/meter'), $stage.' links to the meter once');
        }
    }

    /** What key.js looks for on the meter page, against what the page renders. */
    public function testKeyJsLooksForWhatTheMeterPageRenders(): void
    {
        $html = $this->renderStrictly('manage-meter', ['stage' => 'open', 'site' => $this->site()] + $this->panel());
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/key.js');

        foreach (['data-close-meter', 'data-close-status', 'data-close-controls'] as $hook) {
            self::assertStringContainsString($hook, $html);
            self::assertStringContainsString('['.$hook.']', $script);
        }
        foreach (["'/key/nonce'", "'/key/prove'", "'/meter/close/prepare'", "'/meter/close'"] as $route) {
            self::assertStringContainsString($route, $script);
            self::assertStringContainsString("\$app->post({$route}", (string) file_get_contents(dirname(__DIR__, 2).'/public/index.php'));
        }
    }

    /**
     * A public page carries its lede, and only when it has one (2026-09-14).
     *
     * Both branches, because a rule with two branches and one test is a rule
     * with one branch. `privacy.md` has no lede — the build requires one only
     * of a metered piece — and a page rendering an empty deck there would put
     * a rule and a gap above the body for nothing. A piece that *has* written
     * one has written a standfirst, and the free/paid distinction is no reason
     * to drop it.
     */
    public function testAPublicPageCarriesItsLedeWhenItHasOne(): void
    {
        $withLede = new \Newsprint\Content\Piece(
            'reading-the-inspector', 'How to read the inspector',
            'The panel at the foot of every page has up to eight sections.',
            9, false, 'draft', '2026-09-14', null, '',
        );
        $rendered = $this->renderStrictly('page', ['piece' => $withLede, 'body' => '<p>Body.</p>']);
        self::assertStringContainsString('class="lede deck"', $rendered);
        self::assertStringContainsString('up to eight sections.', $rendered);

        $none = new \Newsprint\Content\Piece('privacy', 'Privacy', '', 4, false, 'draft', '2026-09-04', null, '');
        self::assertStringNotContainsString(
            'deck',
            $this->renderStrictly('page', ['piece' => $none, 'body' => '<p>The list.</p>']),
            'an empty deck is a rule and a gap above the body for nothing',
        );
    }

    /**
     * A piece is titled once.
     *
     * The title was rendered twice on every paid article: once by
     * `article.php` from the front matter, and again by the body, which
     * repeated it as a `#` heading. Two renderings of one string can disagree,
     * and this one was one edit away from doing so. The heading is now the
     * template's on both the article and the page, and the markdown carries
     * none — which is only safe if `page.php` actually renders it, since the
     * privacy page has no other source of a heading.
     */
    public function testEachPieceIsTitledOnce(): void
    {
        $piece = new \Newsprint\Content\Piece('privacy', 'Privacy', '', 4, false, 'draft', '2026-09-04', null, '');

        $page = $this->renderStrictly('page', ['piece' => $piece, 'body' => '<p>Body.</p>']);
        self::assertSame(1, preg_match_all('/<h1[\s>]/i', $page), 'the page carries exactly one heading');
        self::assertStringContainsString('<h1>Privacy</h1>', $page);

        $article = $this->renderStrictly('article', [
            'piece' => new \Newsprint\Content\Piece('two-orderings', 'Two orderings', 'L', 4, true, 'draft', '2026-09-07', null, ''),
            'body' => '<p>Body.</p>',
            'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
            'meter' => $this->panel(['result' => MeterResult::granted()]),
            'previous' => null,
            'next' => null,
        ]);
        self::assertSame(1, preg_match_all('/<h1[\s>]/i', $article), 'the article carries exactly one heading');
        self::assertStringContainsString('<h1>Two orderings</h1>', $article);
    }

    /**
     * And the other half of it: no published source supplies a second one.
     *
     * `bin/build-content` refuses a body with an `<h1>`, but the build is not
     * what runs here, so the sources are scanned directly — and the scanner is
     * run over a broken sample in the same test, because a scanner that
     * matches nothing passes this kind of check silently.
     */
    public function testNoPublishedPieceCarriesItsOwnHeading(): void
    {
        $heading = '/^# /m';

        $checked = 0;
        foreach (glob(dirname(__DIR__, 2).'/content/*.md') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            // Files without front matter are working notes, not pieces:
            // `bin/build-content` skips them and they keep their headings.
            if (!str_starts_with($source, "---\n")) {
                continue;
            }

            ++$checked;
            self::assertDoesNotMatchRegularExpression($heading, $source, basename($path).' carries its own heading');
        }

        self::assertGreaterThan(0, $checked, 'pieces were found to check');
        self::assertMatchesRegularExpression($heading, "---\ntitle: T\n---\n\n# T\n\nBody.\n", 'the scanner finds a heading when there is one');
    }

    /**
     * The dates, and the state that has none.
     *
     * A draft carries its dates in front matter and shows neither, because it
     * has not been published and a creation date standing where a publication
     * date belongs is an answer to a question nobody asked. The badge is what
     * a draft has to say. Both halves are asserted here: the published piece
     * must show the dates, and the draft must not, since a rule that only ever
     * renders one of its two branches has been read, not tested.
     */
    public function testDatesShowOnAPublishedPieceAndOnNoDraft(): void
    {
        $published = new \Newsprint\Content\Piece('privacy', 'Privacy', '', 3, false, 'published', '2026-09-04', '2026-09-07', '');
        $page = $this->renderStrictly('page', ['piece' => $published, 'body' => '<p>The list.</p>']);

        self::assertStringContainsString('<time datetime="2026-09-04">4 September 2026</time>', $page);
        self::assertStringContainsString('· revised <time datetime="2026-09-07">7 September 2026</time>', $page);

        $draft = new \Newsprint\Content\Piece('privacy', 'Privacy', '', 3, false, 'draft', '2026-09-04', '2026-09-07', '');
        $hidden = $this->renderStrictly('page', ['piece' => $draft, 'body' => '<p>The list.</p>']);

        self::assertStringNotContainsString('<time', $hidden);
        self::assertStringNotContainsString('2026', $hidden, 'no date reaches the page in any spelling');
        self::assertStringNotContainsString('class="meta"', $hidden, 'and no empty line is left where one would have gone');

        // On an article the dates share the line with the reading time and the
        // price, and a revision on the day the piece was written says only
        // that the file was saved twice.
        $piece = new \Newsprint\Content\Piece('two-orderings', 'Two orderings', 'L', 4, true, 'published', '2026-09-07', '2026-09-07', '');
        $article = $this->renderStrictly('article', [
            'piece' => $piece,
            'body' => '<p>Body.</p>',
            'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
            'meter' => $this->panel(['result' => MeterResult::granted()]),
            'previous' => null,
            'next' => null,
        ]);

        self::assertStringContainsString('<time datetime="2026-09-07">7 September 2026</time>', $article);
        self::assertStringNotContainsString('revised', $article);
        self::assertStringNotContainsString('draft', $article);

        // **The badge, asserted positively, and that is the point of these
        // three lines.** `assertStringNotContainsString('draft', ...)` above
        // can start passing by looking for a string nothing could contain —
        // reword the badge and it goes quietly green while testing nothing.
        // It is sound only while something asserts the same word appears when
        // it should, and until now nothing did. ('revised' was already
        // anchored, by the assertion on the page above.)
        $unpublished = new \Newsprint\Content\Piece('two-orderings', 'Two orderings', 'L', 4, true, 'draft', '2026-09-07', null, '');
        $badge = $this->renderStrictly('article', [
            'piece' => $unpublished,
            'body' => '<p>Body.</p>',
            'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
            'meter' => $this->panel(['result' => MeterResult::granted()]),
            'previous' => null,
            'next' => null,
        ]);

        self::assertStringContainsString('<span class="draft">draft</span>', $badge);
    }

    /**
     * The two links at the end of a piece, and the two states with none.
     *
     * The nav comes after whatever the page is for — under the body when there
     * is one, under the meter when there is not — and an article at either end
     * of the sequence shows one link, not an empty slot. All of it is asserted
     * here because each is a branch of the template rather than a consequence
     * of the data, and the position is the whole of the decision.
     */
    public function testTheArticleLinksToItsNeighboursUnderTheBody(): void
    {
        $piece = new \Newsprint\Content\Piece('two-orderings', 'Two orderings', 'L', 4, true, 'draft', '2026-09-07', null, '');
        $older = new \Newsprint\Content\Piece('the-delegate', 'The permission nobody shows you', 'L', 4, true, 'draft', '2026-09-05', null, '');
        $newer = new \Newsprint\Content\Piece('request-nobody-made', 'The request nobody made', 'L', 4, true, 'draft', '2026-09-11', null, '');

        $vars = [
            'piece' => $piece,
            'site' => ['symbol' => 'DEMO', 'page_price_demo' => '0.01'],
            'meter' => $this->panel(['result' => MeterResult::granted()]),
        ];

        $html = $this->renderStrictly('article', $vars + ['body' => '<p>Body.</p>', 'previous' => $older, 'next' => $newer]);

        self::assertMatchesRegularExpression('#<a class="previous" rel="prev" href="/a/the-delegate">#', $html);
        self::assertMatchesRegularExpression('#<a class="next" rel="next" href="/a/request-nobody-made">#', $html);
        self::assertStringContainsString('The permission nobody shows you', $html);

        // The nav is above the strip, which is the position the whole thing
        // is about: the end of the reading, before the report of the charge.
        self::assertLessThan(
            (int) strpos($html, 'meter-strip'),
            (int) strpos($html, 'piece-nav'),
            'the links come before the meter strip',
        );

        // One end of the sequence: one link, and no empty half.
        $oneWay = $this->renderStrictly('article', $vars + ['body' => '<p>Body.</p>', 'previous' => $older, 'next' => null]);
        self::assertStringContainsString('rel="prev"', $oneWay);
        self::assertStringNotContainsString('rel="next"', $oneWay);

        // And the gate, where the links come after the offer rather than
        // between the lede and it: a reader who does not want this piece
        // still has somewhere to go, and the one decision the page asks for
        // is not interrupted to say so.
        $gated = $this->renderStrictly('article', $vars + ['body' => null, 'previous' => $older, 'next' => $newer]);
        self::assertStringContainsString('piece-nav', $gated);
        self::assertGreaterThan(
            (int) strpos($gated, 'class="gate'),
            (int) strpos($gated, 'piece-nav'),
            'the links come after the meter, not before it',
        );
    }

    /**
     * The panel writes a base58 once, and every short name can be looked up.
     *
     * Two claims, and they are each other's other half. A section that still
     * wrote an address out in full would make the table decoration; a short
     * name whose row is missing would make the panel a glossary with pages
     * torn out. The second is the one that can break silently — a link to
     * `#name-…` that matches no `id` scrolls nowhere and looks like a click
     * that missed.
     */
    public function testThePanelDefinesEveryShortNameItUses(): void
    {
        $address = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';
        $value = ['value' => $address, 'alias' => 'SPDAmux', 'explorer' => true, 'note' => '["site", AUTHfen] + bump'];

        $html = $this->renderStrictly('inspector-sections', ['sections' => [
            ['heading' => 'The values, in full', 'names' => [$value]],
            ['heading' => 'Site account, decoded', 'rows' => [['site account', $value], ['bump', '254']]],
            ['heading' => 'The last transaction', 'rows' => [['ix 1 · account 1', $value, 'writable']]],
        ]]);

        // Three sightings of one address, and one place it is written out.
        self::assertSame(3, substr_count($html, 'SPDAmux'), 'the table and the two rows');
        // Three times in one cell — the explorer href, the text of the link
        // and the copy button's attribute — and nowhere else on the page.
        self::assertSame(3, substr_count($html, $address));
        self::assertSame(1, preg_match_all('/data-copy-address/', $html), 'one copy button per address, not one per sighting');

        preg_match_all('/href="#([^"]+)"/', $html, $hrefs);
        preg_match_all('/id="([^"]+)"/', $html, $ids);
        self::assertNotEmpty($hrefs[1], 'the short names are links');
        self::assertSame([], array_diff($hrefs[1], $ids[1]), 'every short name links to a row that is there');
    }

    /**
     * The deferred event spans, like the signature above it.
     *
     * It is a sentence and there is no check beside it to mirror, so stopping
     * at the value column wrapped it inside a third of the width.
     */
    /**
     * A claim that is a sentence goes under the value; a flag stays beside it.
     *
     * This is what lets the panel be the article's width: the two sections
     * carrying sentences were the only ones needing 52rem, and they needed it
     * for a third column. The section says which form it wants rather than the
     * template measuring the text, so a reworded claim cannot silently change
     * the panel's anatomy — and both forms are rendered here, because a rule
     * with two branches and one test is a rule with one branch.
     */
    public function testASentenceGoesUnderTheValueAndAFlagStaysBesideIt(): void
    {
        $under = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'Preflight, for this request',
            'claims' => 'under',
            'rows' => [['can_meter', 'yes', 'require!(new_used <= limit, LimitReached)']],
        ]]]);

        self::assertStringContainsString('<span class="beneath">require!(new_used &lt;= limit, LimitReached)</span>', $under);
        self::assertStringNotContainsString('class="mirrors"', $under, 'no third column, which is the point');
        self::assertStringNotContainsString('class="mirrored"', $under);

        $beside = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'The last transaction',
            'rows' => [['ix 1 · account 1', 'CPDAash', 'writable']],
        ]]]);

        self::assertStringContainsString('<td class="mirrors">writable</td>', $beside);
        self::assertStringNotContainsString('beneath', $beside);
    }

    /**
     * A section link leaves the site only when it says it does.
     *
     * `target="_blank" rel="noreferrer noopener"` was unconditional while the
     * only link in the panel went to the block explorer. The first link to
     * one of this site's own screens would have opened it in a second tab,
     * which is a thing to notice once and never again.
     */
    public function testOnlyAnExternalSectionLinkOpensElsewhere(): void
    {
        $away = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'The last transaction',
            'rows' => [['page views', '1']],
            'link' => ['href' => 'https://explorer.solana.com/tx/FKb3eeBw', 'text' => 'This transaction on chain', 'external' => true],
        ]]]);

        self::assertStringContainsString('target="_blank"', $away);
        self::assertStringContainsString('rel="noreferrer noopener"', $away);

        $here = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'This site, on chain',
            'rows' => [['status', 'not provisioned']],
            'link' => ['href' => '/setup', 'text' => 'Run first-run setup'],
        ]]]);

        self::assertStringContainsString('href="/setup"', $here);
        self::assertStringNotContainsString('target="_blank"', $here);
        self::assertStringNotContainsString('rel=', $here);
    }

    public function testTheDeferredEventRowSpansTheMirroredColumn(): void
    {
        $html = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'The last transaction',
            'rows' => [['page views', '1', 'this call settles']],
            'event' => 'FKb3eeBw',
        ]]]);

        self::assertMatchesRegularExpression('/<td data-event-slot colspan="2"/', $html);

        // And does not span where there is no second column to span into.
        $plain = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'The last transaction',
            'rows' => [['page views', '1']],
            'event' => 'FKb3eeBw',
        ]]]);

        self::assertMatchesRegularExpression('/<td data-event-slot>/', $plain);
    }

    /**
     * **While the charge is out, the strip shows no account figure** (§7.3,
     * §2's claim 7, 2026-09-17).
     *
     * The article is served as soon as the endpoint accepts its charge, and a
     * read taken then would return the accounts from before it. The panel
     * handed to the template still carries those figures — this request did
     * read them — so the test is that the strip declines to draw them, and
     * the heads-up and the advance that are worked out from them. The same
     * strip with the charge settled is rendered alongside, so the negative
     * assertions are looking for phrases that do appear.
     */
    public function testAStripWhoseChargeIsOutShowsNoFigure(): void
    {
        $piece = new \Newsprint\Content\Piece('two-orderings', 'T', 'L', 4, true, 'draft', '2026-09-07', null, '');
        $render = function (MeterResult $result) use ($piece): string {
            $meter = $this->panel(['result' => $result, 'solvency' => $this->solvency(80000)]);

            return $this->renderStrictly('meter-strip', ['result' => $result, 'meter' => $meter, 'piece' => $piece]);
        };

        $settled = $render(MeterResult::granted());
        foreach (['used 0.42', 'Heads up:', 'data-advance'] as $phrase) {
            self::assertStringContainsString($phrase, $settled, 'the control shows '.$phrase);
        }
        self::assertStringNotContainsString('data-charge-pending', $settled);

        foreach ([
            'sent on this request' => [MeterResult::servedAhead('sigone', 10_000, false), 'Charging 0.01 DEMO for this article.'],
            'a grant still out' => [MeterResult::granted(ChargeState::Pending, true), 'The network is still confirming its'],
        ] as $label => [$result, $words]) {
            $html = $render($result);

            self::assertStringContainsString($words, $html, $label);
            foreach (['used 0.42', 'Heads up:', 'data-advance>'] as $phrase) {
                self::assertStringNotContainsString($phrase, $html, $label.': no '.$phrase);
            }
            self::assertStringContainsString('data-charge-pending="/a/two-orderings/confirm"', $html, $label.': where the page asks');
            self::assertStringContainsString('<script type="module" src="/assets/charge.js"></script>', $html, $label);
            self::assertMatchesRegularExpression('/<p class="fine" data-charge-failed hidden><\/p>/', $html, $label.': the failure line is empty and hidden');
            self::assertMatchesRegularExpression('/<noscript>.*<a href="\/a\/two-orderings">.*<\/noscript>/s', $html, $label.': and without JavaScript, a link back to the GET, not a reload that resubmits');
            self::assertStringContainsString('<a href="/meter">The meter</a>', $html, $label.': the way out, while the line that usually carries it is withheld');
        }

        self::assertFileExists(dirname(__DIR__, 2).'/public/assets/charge.js');
    }

    /**
     * The follow-up's reports. A report that has waited the window out asks
     * nothing further by itself, and says what the reader can do instead.
     */
    public function testTheFollowUpReportsSayWhatBecameOfTheCharge(): void
    {
        $piece = new \Newsprint\Content\Piece('two-orderings', 'T', 'L', 4, true, 'draft', '2026-09-07', null, '');
        $render = function (MeterResult $result) use ($piece): string {
            $meter = $this->panel(['result' => $result]);

            return $this->renderStrictly('meter-strip', ['result' => $result, 'meter' => $meter, 'piece' => $piece]);
        };

        $settled = $render(MeterResult::confirmedLater('sigone', true));
        self::assertStringContainsString('This one settled', $settled);
        self::assertStringContainsString('used 0.42', $settled, 'figures are back, from a read after the answer');

        $quiet = $render(MeterResult::confirmedLater('sigone', false));
        self::assertStringContainsString('Nothing moved', $quiet);

        // The event did not answer: neither sentence, rather than a guess.
        $unread = $render(MeterResult::confirmedLater('sigone', null));
        self::assertStringContainsString('Metered 0.01 DEMO for this article.', $unread);
        self::assertStringNotContainsString('This one settled', $unread);
        self::assertStringNotContainsString('Nothing moved', $unread);

        $absorbed = $render(MeterResult::absorbed('sigone', null, 'transaction failed on chain'));
        self::assertStringContainsString('The network turned this charge down', $absorbed);
        self::assertStringContainsString('The article is', $absorbed);
        self::assertStringContainsString('used 0.42', $absorbed);

        $dropped = $render(MeterResult::unconfirmedLater('sigone', ChargeState::Unknown));
        self::assertStringContainsString('never reached the network', $dropped);

        $late = $render(MeterResult::unconfirmedLater('sigone', ChargeState::Pending));
        self::assertStringContainsString('Reload later to see whether it landed', $late);
        self::assertStringNotContainsString('used 0.42', $late, 'still no answer, so still no figures');

        foreach ([$settled, $absorbed, $dropped, $late] as $html) {
            self::assertStringNotContainsString('data-charge-pending', $html, 'a report does not ask again by itself');
        }

        // A grant whose charge was settled on an earlier visit says so once.
        self::assertStringContainsString('turned its charge down', $render(MeterResult::granted(ChargeState::Refused)));
        self::assertStringContainsString('asked the chain one', $render(MeterResult::granted(ChargeState::Confirmed, true)));
        self::assertStringContainsString('The chain was not', $render(MeterResult::granted()));
    }

    /**
     * `charge.js` looks for what the strip renders, and hands its POST to the
     * same swap as the other two controls.
     */
    public function testChargeJsLooksForWhatTheStripRenders(): void
    {
        $root = dirname(__DIR__, 2);
        $strip = (string) file_get_contents($root.'/templates/meter-strip.php');
        $charge = (string) file_get_contents($root.'/public/assets/charge.js');

        preg_match_all('/\[data-charge([a-z-]*)\]/', $charge, $sought);
        self::assertNotSame([], $sought[1], 'a scanner that found nothing proves nothing');
        foreach (array_unique($sought[1]) as $suffix) {
            self::assertMatchesRegularExpression('/\sdata-charge'.preg_quote($suffix, '/').'[\s>=]/', $strip, 'data-charge'.$suffix);
        }

        self::assertStringContainsString('dataset.chargePending', $charge);
        self::assertStringContainsString("import { swap } from './swap.js'", $charge);
        // Delegated, for the reason `advance.js` is: the strip arrives inside
        // a swap, and this module cannot run twice in one document.
        self::assertStringContainsString("document.addEventListener('newsprint:swapped'", $charge);
        self::assertStringContainsString('document.prerendering', $charge);
    }

    /**
     * **The builders' instructions survive the follow-up** (§9, 2026-09-17).
     *
     * Only the request that built a transaction holds its instructions. The
     * follow-up that confirms a charge renders a panel of its own, with a
     * stand-in row where they would go; `swap.js` moves the sending page's
     * rows into it. So the two ends are rendered here and matched: the rows
     * marked with a signature, the slot marked with the same one, and the
     * script reading both attributes.
     */
    public function testTheInstructionRowsAndTheirSlotAreMarkedForTheSwap(): void
    {
        $sent = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'The last transaction',
            'rows' => [['signature', 'sigone'], ['ix 1 · program', 'PRGMfoo'], ['ix 1 · account 1', 'CPDAash', 'writable'], ['ix 1 · data', '0102']],
            'instructions' => 'sigone',
        ]]]);
        self::assertSame(3, substr_count($sent, 'data-ix-of="sigone"'), 'every instruction row, and only those');
        self::assertStringNotContainsString('data-ix-slot', $sent);

        $later = $this->renderStrictly('inspector-sections', ['sections' => [[
            'heading' => 'The last transaction',
            'rows' => [['signature', 'sigone'], ['instructions', 'built by the request that sent this charge', 'signature and event only']],
            'carry' => 'sigone',
        ]]]);
        self::assertSame(1, substr_count($later, 'data-ix-slot="sigone"'));
        self::assertStringNotContainsString('data-ix-of', $later);

        $swap = (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/swap.js');
        self::assertStringContainsString('[data-ix-slot]', $swap);
        self::assertStringContainsString('dataset.ixSlot', $swap);
        self::assertStringContainsString('[data-ix-of=', $swap);
    }

    public function testAMeterDecodesIntoThePanelWithoutGuessingAtItsShape(): void
    {
        // Not a render: a reminder that `Meter` is a value object with named
        // fields and a derived `unpaid()`, and that reading anything else off
        // it is the mistake this whole file exists for.
        $meter = new Meter(self::SITE, self::FUND, self::KEY, 1_800_000_000, 500000, 420000, 280000, 255);

        self::assertSame(140000, $meter->unpaid());
        self::assertFalse($meter->expired(1_800_000_000), 'the program still meters at the expiry itself');
    }
}
