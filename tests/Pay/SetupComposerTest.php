<?php

declare(strict_types=1);

namespace Newsprint\Tests\Pay;

use Newsprint\Pay\Composition;
use Newsprint\Pay\SetupAnswers;
use Newsprint\Pay\SetupComposer;
use Newsprint\Pay\SetupRefusal;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Fund;
use SolPay\Core\Ix;
use SolPay\Core\Meter;
use SolPay\Core\Pda;
use SolPay\Core\Program;
use SolPay\Core\Site;
use SolPay\Core\TokenAccount;

/**
 * SPEC §6.3's composition rules and refusals, without a chain. Each case is
 * one of the situations `bin/fund-trials` composed on devnet on 2026-09-30,
 * and the instructions are compared with what `SolPay\Core\Ix` builds for the
 * same arguments, so a test fails if the order or an argument moves.
 */
final class SetupComposerTest extends TestCase
{
    private const SITE = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';
    private const MINT = 'AKRs35WnmePSDLcPLyVtC5UPPBC8xjmVZ4EUJ3XwU9op';
    private const WALLET = 'G4jQTrS8unMpXunB2mkfX7LffPSPbRXsySTQvvfVUoah';
    private const KEY = 'D5VoE7hnS4yDaaMcEfgwcPyMnwNG5Xb2j4WFFFtHKwKq';
    private const NOW = 1_800_000_000;

    private Program $program;
    private string $fund;
    private string $holding;

    protected function setUp(): void
    {
        $this->program = Program::default();
        $this->fund = Pda::fundAddress(self::WALLET, self::MINT, 0)['address'];
        $this->holding = $this->composer()->addresses(self::WALLET, 0)['holding'];
    }

    /** §13.1 step 3: fund 0 absent, a deposit of 0.50, a meter for this browser. */
    public function testAFirstVisitOpensTheFundDepositsAndOpensTheMeter(): void
    {
        $made = $this->compose($this->answers(deposit: 500_000), fund: null, meter: null);

        self::assertInstanceOf(Composition::class, $made);
        self::assertEquals([
            Ix::openFund($this->program, self::WALLET, self::MINT, 0),
            Ix::deposit($this->program, $this->holding, self::WALLET, $this->fund, self::MINT, 500_000, 6),
            Ix::openMeter($this->program, self::SITE, self::WALLET, $this->fund, self::KEY, 500_000, self::NOW + 86_400),
        ], $made->instructions, 'open_fund before the deposit: the fund\'s token account has to exist first');
        self::assertSame('Open meter, limit 0.5 DEMO; deposit 0.5 DEMO; new fund 0.', $made->message());
        self::assertSame(Pda::meterAddress(self::SITE, $this->fund)['address'], $made->meter);
    }

    public function testNoDepositIsNoTransfer(): void
    {
        $made = $this->compose($this->answers(deposit: 0), fund: null, meter: null);

        self::assertInstanceOf(Composition::class, $made);
        self::assertCount(2, $made->instructions, 'open_fund and open_meter');
    }

    /** §6.4: a second device, same fund. The meter exists, so it is renewed to the new key. */
    public function testAnExistingMeterIsRenewedToThisBrowsersKey(): void
    {
        $made = $this->compose($this->answers(deposit: 0, expiry: 'hour'), fund: $this->fund(), meter: $this->meter(used: 70_000));

        self::assertInstanceOf(Composition::class, $made);
        self::assertEquals([
            Ix::renewMeter($this->program, self::SITE, self::WALLET, $this->fund, self::KEY, 500_000, self::NOW + 3_600),
        ], $made->instructions);
    }

    public function testADepositIntoAnExistingFundIsATransferAlone(): void
    {
        $made = $this->compose($this->answers(deposit: 100_000), fund: $this->fund(), meter: $this->meter());

        self::assertInstanceOf(Composition::class, $made);
        self::assertCount(2, $made->instructions, 'the deposit and the renewal, and no open_fund');
    }

    /** Devnet taught this twice on 2026-09-30: SPL refused, and the wallet would have shown it as its own failure. */
    public function testADepositTheWalletCannotCoverIsRefused(): void
    {
        $refused = $this->compose($this->answers(deposit: 600_001), fund: null, meter: null, holding: 600_000);
        self::assertInstanceOf(SetupRefusal::class, $refused);
        self::assertSame('A deposit of 0.600001 DEMO from a wallet holding 0.6 DEMO: the wallet cannot cover it.', $refused->message);

        self::assertInstanceOf(SetupRefusal::class, $this->compose($this->answers(deposit: 1), fund: null, meter: null, holding: null), 'no token account holds nothing');
        self::assertInstanceOf(Composition::class, $this->compose($this->answers(deposit: 600_000), fund: null, meter: null, holding: 600_000), 'all of it is fine');
    }

    public function testALimitBelowTheFloorIsRefused(): void
    {
        self::assertInstanceOf(SetupRefusal::class, $this->compose($this->answers(limit: 499_999), fund: null, meter: null));

        // A renewal's floor folds in the residue it carries.
        $carrying = $this->meter(used: 640_000, paid: 100_000, limit: 700_000);
        $refused = $this->compose($this->answers(limit: 500_000, deposit: 0), fund: $this->fund(), meter: $carrying);
        self::assertInstanceOf(SetupRefusal::class, $refused);
        self::assertSame('The smallest limit this meter can take is 0.54 DEMO.', $refused->message);
    }

    /** §6.4: *add to the fund* composes the transfer and touches no meter. */
    public function testAddingToTheFundIsTheTransferAndNothingElse(): void
    {
        $answers = new SetupAnswers(SetupAnswers::DEPOSIT, 0, 100_000, fund: $this->fund);
        $made = $this->compose($answers, fund: $this->fund(), meter: $this->meter());

        self::assertInstanceOf(Composition::class, $made);
        self::assertEquals([Ix::deposit($this->program, $this->holding, self::WALLET, $this->fund, self::MINT, 100_000, 6)], $made->instructions);

        self::assertInstanceOf(SetupRefusal::class, $this->compose($answers, fund: null, meter: null), 'no fund, nothing to add to');
    }

    /** A setup started from a session names its fund, and only that fund's wallet may answer it. */
    public function testAnotherWalletCannotAnswerASessionsSetup(): void
    {
        $theirs = Pda::fundAddress('3qZLoqdTunZJUANtRpaAnpFewAW4UVkgPn6VWzWTk4Mw', self::MINT, 0)['address'];
        $answers = new SetupAnswers(SetupAnswers::DEPOSIT, 0, 100_000, fund: $theirs);

        $refused = $this->compose($answers, fund: $this->fund(), meter: null);
        self::assertInstanceOf(SetupRefusal::class, $refused);
        self::assertStringContainsString('another wallet', $refused->message);
    }

    private function composer(): SetupComposer
    {
        return new SetupComposer(
            Program::default(),
            self::SITE,
            new Site('163aJWGmry7Q2gWjtxmTbdC7NGFc7FecSN1gfpNUgRt', self::MINT, 'TrSy11111111111111111111111111111111111111', 10_000, 100_000, 500_000, 254),
            6,
            'DEMO',
        );
    }

    private function compose(SetupAnswers $answers, ?Fund $fund, ?Meter $meter, ?int $holding = 600_000): Composition|SetupRefusal
    {
        return $this->composer()->compose(
            $answers,
            self::WALLET,
            $fund,
            $meter,
            $holding === null ? null : new TokenAccount(self::MINT, self::WALLET, $holding, null, 0),
            self::NOW,
        );
    }

    private function answers(int $deposit = 500_000, int $limit = 500_000, string $expiry = 'day'): SetupAnswers
    {
        return new SetupAnswers(SetupAnswers::SETUP, 0, $deposit, $limit, $expiry, self::KEY);
    }

    private function fund(): Fund
    {
        return new Fund(self::WALLET, self::MINT, 0, 1, 253);
    }

    private function meter(int $used = 0, int $paid = 0, int $limit = 500_000): Meter
    {
        return new Meter(self::SITE, $this->fund, 'OLDkey1111111111111111111111111111111111111', self::NOW + 60, $limit, $used, $paid, 252);
    }
}
