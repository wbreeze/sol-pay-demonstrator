<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * What a failure report carries, and the one thing it did not.
 *
 * `POST /diagnostics/report` writes to `var/wallet-reports/`, and the point of
 * it is that a wallet says "Unexpected error" for a cluster that refused the
 * send, an expired blockhash and a failed simulation alike — so the page writes
 * down what it knows instead of asking somebody to transcribe a console.
 *
 * **On 2026-09-10 it was missing the only field that would have mattered.**
 * Three identical `-32603` reports came out of Phantom during `solana:signIn`,
 * each with `prepare: null` and `instructions: []`, because both belong to the
 * transaction paths and sign-in has neither. The cause was Phantom's side panel
 * not noticing it had locked, so nothing here was at fault — and had something
 * been, the first thing anybody would have asked for is the challenge the wallet
 * was handed, which no report contained.
 *
 * Textual, because the subject is what the browser sends and a PHP test cannot
 * run `meter.js`. What it can do is insist that the field is recorded before the
 * call that fails, and that the report carries it.
 */
final class FailureReportTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testTheReportCarriesTheSignInChallenge(): void
    {
        $tx = $this->asset('tx.js');

        self::assertStringContainsString('signIn: ctx.trace.signIn,', $tx, 'the report has to send it');
        self::assertMatchesRegularExpression(
            '/const trace = \{[^}]*\bsignIn: null\b/',
            $tx,
            'and the trace has to have somewhere to put it, so an untouched path reports null rather than undefined',
        );
    }

    /**
     * Written down *before* the wallet is called, which is the whole point: the
     * call is what throws, and a line after it never runs.
     */
    public function testTheChallengeIsRecordedBeforeTheWalletIsCalled(): void
    {
        $meter = $this->asset('meter.js');

        $recorded = strpos($meter, 'ctx.trace.signIn = {');
        $called = strpos($meter, 'await signIn(wallet, challenge.input)');

        self::assertIsInt($recorded, 'meter.js records the challenge on the trace');
        self::assertIsInt($called, 'meter.js calls the wallet');
        self::assertLessThan($called, $recorded, 'a record written after the throw is no record');
    }

    /**
     * And not the signature. The input is this page's question; the signed
     * message and the signature are the reader's answer, and a diagnostic file
     * has no use for them.
     */
    public function testTheReportDoesNotKeepWhatTheWalletSigned(): void
    {
        $meter = $this->asset('meter.js');

        self::assertStringContainsString('ctx.trace.signIn = { wallet: wallet.name, input: challenge.input };', $meter);
        self::assertStringNotContainsString('signedMessage: base64(output.signedMessage), signature', $this->asset('tx.js'));
    }

    private function asset(string $name): string
    {
        return (string) file_get_contents(self::ROOT.'/public/assets/'.$name);
    }
}
