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

    /**
     * The advance's progress sentence, and the one assumption `advance.js`
     * makes about the markup: that the server never renders the button
     * disabled or the sentence shown. The script resets both on arrival, which
     * is only safe if neither can be the server's own state.
     */
    public function testTheAdvanceFormCarriesItsProgressSentenceHidden(): void
    {
        $piece = new \Newsprint\Content\Piece('two-orderings', 'T', 'L', 4, true, 'draft', '');
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
        $piece = new \Newsprint\Content\Piece('two orderings', 'T', 'The lede.', 4, true, 'draft', '');
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
     * What `read-on.js` looks for, against what the templates render.
     *
     * The script finds the shell by three data attributes and takes two parts
     * out of the POST's answer — `article.piece` and `.inspector-body`. Rename
     * any of them on one side only and the article sits on "Checking your
     * meter…" for good, having charged, with no error anywhere: the fragment
     * arrives, the selector finds nothing, and the body never lands. So the
     * two sides are read and compared.
     */
    public function testReadOnJsLooksForWhatTheTemplatesRender(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/public/assets/read-on.js');
        $shell = (string) file_get_contents($root.'/templates/article-pending.php');

        preg_match_all('/\[(data-read-on[a-z-]*)\]/', $script, $sought);
        self::assertNotSame([], $sought[1], 'a scanner that found nothing proves nothing');
        foreach (array_unique($sought[1]) as $attribute) {
            self::assertMatchesRegularExpression('/\s'.preg_quote($attribute, '/').'[\s>]/', $shell, $attribute);
        }

        // The shell is replaced by the answer's article, so both are one.
        self::assertStringContainsString("querySelector('article.piece')", $script);
        self::assertStringContainsString('<article class="piece">', $shell);
        self::assertStringContainsString('<article class="piece">', (string) file_get_contents($root.'/templates/article.php'));

        self::assertStringContainsString("querySelector('.inspector-body')", $script);
        self::assertStringContainsString('class="inspector-body"', (string) file_get_contents($root.'/templates/inspector.php'));

        // And the header the POST route branches on is the one the script sends.
        self::assertStringContainsString("'X-Fragment': '1'", $script);

        // The threshold travels as `data-after-ms`, read as `dataset.afterMs`.
        self::assertStringContainsString('data-after-ms=', $shell);
        self::assertStringContainsString('dataset.afterMs', $script);
    }

    /**
     * **The way out is on every screen that holds a wallet** (§6, §10.4).
     *
     * It used to sit in the panel's last branch, which the trace of 2026-09-09
     * found is reachable only on a prefetch — so the "permanent" link had
     * almost certainly never been on a screen, while `unfunded`, `set-meter`
     * and `unreadable` stored an address and offered nothing. Those three are
     * the states a stuck reader is in.
     *
     * The condition is the stored wallet and not the contract, because §10.4's
     * promise is about the address rather than the authorization — and the
     * wording follows the contract, because §6's "you have spent" is false for
     * a reader who has not spent anything.
     */
    public function testEveryStageHoldingAWalletOffersTheWayOut(): void
    {
        $shortfall = Shortfall::diagnose(new TokenAccount(self::MINT, self::PAYER, 1000, self::CONTRACT, 500000), 210000);
        $site = ['symbol' => 'DEMO', 'page_price_demo' => '0.01'];

        $render = function (string $stage, bool $contract) use ($shortfall, $site): string {
            $meter = $this->panel([
                'stage' => $stage,
                'blocked' => (string) Blocked::limitReached(1000),
                'result' => MeterResult::failed('refused', null, $shortfall, null),
            ]);
            if (!$contract) {
                $meter['contract'] = null;
                $meter['views_remaining'] = null;
            }

            return self::said($this->renderStrictly('meter', ['meter' => $meter, 'site' => $site]));
        };

        // A reader who has authorized: §6's case, and the wording it asks for.
        foreach (['failed', 'limit', 'metered'] as $stage) {
            $html = $render($stage, true);
            self::assertStringContainsString('href="/meter"', $html, $stage);
            self::assertStringContainsString('what you have spent', $html, $stage);
        }

        // A reader who has not, and whose address the site is holding anyway:
        // §10.4's case. These three are where a stuck reader is.
        foreach (['unreadable', 'unfunded', 'set-meter'] as $stage) {
            $html = $render($stage, false);
            self::assertStringContainsString('href="/meter"', $html, $stage);
            self::assertStringContainsString('holding for you', $html, $stage);
            self::assertStringNotContainsString('what you have spent', $html, $stage);
        }

        // Nobody's wallet, nothing to offer: a visitor who has not identified
        // is not invited to be forgotten.
        $anonymous = self::said($this->renderStrictly('meter', [
            'meter' => $this->panel(['stage' => 'anonymous', 'wallet' => null, 'contract' => null, 'views_remaining' => null]),
            'site' => $site,
        ]));
        self::assertStringNotContainsString('href="/meter"', $anonymous);
    }

    /**
     * `/meter` offers the forget path in every state that holds a wallet,
     * including `unreadable` — the state where it is the only thing the site
     * can still do, because `POST /signout` asks the chain nothing.
     */
    public function testTheMeterPageOffersForgettingEvenWhenTheChainIsUnreadable(): void
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

        foreach (['unreadable', 'no-contract', 'open'] as $stage) {
            $html = self::said($this->renderStrictly('manage-meter', ['stage' => $stage] + $common));
            self::assertStringContainsString('action="/signout"', $html, $stage);
        }

        // What it does not do is guess. With the chain unreadable it says
        // neither that a contract exists nor that none does. Compared with the
        // whitespace collapsed, because these sentences are wrapped in the
        // template and a line break is not a difference in what was said.
        $unreadable = self::said($this->renderStrictly('manage-meter', ['stage' => 'unreadable'] + $common));
        self::assertStringNotContainsString('You have no contract', $unreadable);
        self::assertStringNotContainsString('Your contract stays open', $unreadable);

        // And a visitor with no wallet is not offered it.
        $anonymous = self::said($this->renderStrictly('manage-meter', ['stage' => 'anonymous'] + $common));
        self::assertStringNotContainsString('action="/signout"', $anonymous);
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
