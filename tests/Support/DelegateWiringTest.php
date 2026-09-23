<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Whose delegate is on the token account, asked everywhere it decides something.
 *
 * `Shortfall::diagnose` reports `delegate !== null`, and an account holds one
 * delegate, so another site's `approve` reads as a delegate in good standing.
 * Three places acted on that boolean: the panel, the close, and the receipt.
 *
 * SPL `revoke` clears whatever delegate is set rather than a particular one,
 * and a token account holds exactly one. So a reader who authorized another
 * site after this one, and then closed here, lost that site's permission —
 * silently, and to a site with no business touching it.
 *
 * The decision is the browser's, because it is the browser that builds the
 * instructions; the *fact* is the server's, read from the account in
 * `/meter/close/prepare`. Textual, like {@see LateCloseWiringTest}: the front
 * controller is one file of closures and `manage.js` is a module a unit test
 * here cannot load.
 */
final class DelegateWiringTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * The panel carries the narrower fact, not the library's boolean.
     *
     * Every screen downstream reads `delegate_is_ours`, and the template tests
     * build that array themselves, so this is the only place that checks the
     * front controller fills it from the right question.
     */
    public function testThePanelAsksWhetherTheDelegateIsThisSitesContract(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/public/index.php');

        self::assertStringContainsString("'delegate_is_ours' => \$payer->delegateIsContract()", $source);
        self::assertStringContainsString("\$panel['delegate_is_ours'] = \$payer->delegateIsContract();", $source);
        self::assertStringNotContainsString("'delegate_present' => \$shortfall->delegatePresent", $source, 'the boolean that cannot tell whose delegate it is');
        self::assertStringContainsString("'clear' => \$shortfall->isClear() && \$payer->delegateIsContract()", $source, "a clear account is one this site can actually draw on");
    }

    /** The server answers whose delegate it is, from the account it just read. */
    public function testPrepareReportsWhetherTheDelegateIsThisSitesContract(): void
    {
        $prepare = $this->routeBody('/meter/close/prepare');

        self::assertStringContainsString("'delegateIsContract' => \$payer->delegateIsContract()", $prepare);
    }

    /** And again after the close, so the receipt can tell the two cases apart. */
    public function testDoneReportsItToo(): void
    {
        $done = $this->routeBody('/meter/close/done');

        self::assertStringContainsString("'delegateIsContract' => \$payer->delegateIsContract()", $done);
    }

    /**
     * The browser sends `close_contract` alone when the delegate is not ours.
     *
     * Asserted as the pair, because either half alone is the bug: a
     * `closeAndRevoke` with no alternative revokes another site's approval, and
     * a `closeContract` with no alternative never revokes this site's.
     */
    public function testTheBrowserChoosesBetweenCloseAndCloseWithRevoke(): void
    {
        $js = (string) file_get_contents(self::ROOT.'/public/assets/manage.js');

        self::assertStringContainsString('prep.delegateIsContract !== false', $js, 'the fact comes from the account, not from this page');
        self::assertStringContainsString('pay.closeAndRevoke(prep.payerTokenAccount, prep.payer, prep.site)', $js);
        self::assertStringContainsString('pay.closeContract(prep.site, prep.payer)', $js);
        self::assertMatchesRegularExpression(
            '/ours\s*\n\s*\?\s*pay\.closeAndRevoke\(.*\)\s*\n\s*:\s*pay\.closeContract\(/',
            $js,
            'the revoke is conditional on the delegate being this site\'s',
        );
    }

    /**
     * A surviving delegate is an alarm only when this site is the one that
     * should have withdrawn it. Reporting the other case as STILL SET would
     * accuse the site of failing to do what it declined to do on purpose.
     */
    public function testTheReceiptDistinguishesADelegateLeftAloneFromOneNotWithdrawn(): void
    {
        $js = (string) file_get_contents(self::ROOT.'/public/assets/manage.js');

        self::assertStringContainsString('result.delegateIsContract === false', $js);
        self::assertStringContainsString('left as it was', $js);
        self::assertStringContainsString('STILL SET: ', $js);
    }

    /** One route out of `public/index.php`, from its path to the next one. */
    private function routeBody(string $path): string
    {
        $source = (string) file_get_contents(self::ROOT.'/public/index.php');
        $start = strpos($source, "'".$path."'");
        self::assertNotFalse($start, $path.' is not routed');
        $end = strpos($source, '$app->', $start + 1);

        return substr($source, $start, $end === false ? null : $end - $start);
    }
}
