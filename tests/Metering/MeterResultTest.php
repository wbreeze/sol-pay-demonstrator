<?php

declare(strict_types=1);

namespace Newsprint\Tests\Metering;

use Newsprint\Chain\PayerState;
use Newsprint\Metering\MeterResult;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Blocked;
use SolPay\Core\Contract;
use SolPay\Core\Site;

/**
 * One rule, asserted from both sides: **a metering result carries the accounts
 * it decided from exactly when no transaction went out.**
 *
 * The saving is the easy half. `Meter` reads the contract and the reader's
 * token account to decide, and the request then renders from those same two
 * accounts; where nothing was sent, reading them again is a second ~500 ms
 * round trip for identical bytes. Measured 2026-09-09: the `set-meter` screen
 * cost three `getMultipleAccounts` and 1.88 s, one whole call of which was
 * discarded.
 *
 * The rule's other half is the one worth a test. `metered`, `unconfirmed` and
 * `failed` all follow a `sendTransaction`, and on those `used`, `paid` and the
 * carried residue may have moved. §2's claim 7 is that every number on the
 * screen came from an account and not from the server's memory — so a
 * pre-send read carried onto one of those screens would be stale arithmetic
 * wearing fresh arithmetic's clothes, and nothing about the page would look
 * wrong. That failure has no symptom, which is why it is a test and not a
 * comment.
 */
final class MeterResultTest extends TestCase
{
    /** The outcomes that follow a `sendTransaction`, plus the one that reads nothing at all. */
    private const SENT_OR_READ_NOTHING = ['granted', 'metered', 'unconfirmed', 'failed'];

    public function testTheOutcomesThatSendNothingCarryTheAccountsTheyDecidedFrom(): void
    {
        $payer = $this->payer();

        self::assertSame($payer, MeterResult::unreadable('there is no contract for this reader', $payer)->payer);
        self::assertSame($payer, MeterResult::blocked(Blocked::limitReached(400), 10_000, $payer)->payer);
    }

    /**
     * A read that failed has nothing to carry, and says so rather than
     * carrying a half-built one.
     */
    public function testAnEndpointThatDidNotAnswerCarriesNothing(): void
    {
        self::assertNull(MeterResult::unreadable('the endpoint did not answer')->payer);
    }

    public function testEveryOutcomeThatSentSomethingCarriesNoPayer(): void
    {
        self::assertNull(MeterResult::granted()->payer);
        self::assertNull(MeterResult::metered('sig', 10_000, true, 1, [])->payer);
        self::assertNull(MeterResult::unconfirmed('sig', 10_000, false, 1, [])->payer);
        self::assertNull(MeterResult::failed('refused', null, null, 'sig', [])->payer);
    }

    /**
     * And they cannot be made to, which is the direction that matters.
     *
     * The check above passes for as long as nobody passes a payer to those
     * factories; this one fails the moment somebody gives them somewhere to
     * put one. A default-null parameter added to `metered()` in the course of
     * some other repair would sail past a green suite and quietly put a
     * pre-send balance on the screen that reports the charge.
     */
    public function testTheSendingFactoriesHaveNowhereToPutAPayer(): void
    {
        $problems = [];

        foreach (self::SENT_OR_READ_NOTHING as $factory) {
            $method = new \ReflectionMethod(MeterResult::class, $factory);
            foreach ($method->getParameters() as $parameter) {
                $type = (string) $parameter->getType();
                if (str_contains($type, PayerState::class)) {
                    $problems[] = "MeterResult::{$factory}() accepts a PayerState as \${$parameter->getName()}"
                        .' — that outcome follows a send, so the accounts it would carry may be stale';
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    private function payer(): PayerState
    {
        $site = new Site(
            authority: '163aJWGmry7Q2gWjtxmTbdC7NGFc7FecSN1gfpNUgRt',
            mint: 'MintI1111111111111111111111111111111111111',
            treasury: 'TrSy11111111111111111111111111111111111111',
            pagePrice: 10_000,
            collectionThreshold: 100_000,
            minLimit: 500_000,
            bump: 254,
        );

        return new PayerState(
            wallet: 'PayR11111111111111111111111111111111111111',
            contractAddress: 'CtRc11111111111111111111111111111111111111',
            tokenAccount: 'AtA111111111111111111111111111111111111111',
            contract: new Contract(
                site: '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt',
                payer: 'PayR11111111111111111111111111111111111111',
                limit: 500_000,
                used: 230_000,
                paid: 150_000,
                bump: 253,
            ),
            funds: null,
            site: $site,
            decimals: 6,
        );
    }
}
