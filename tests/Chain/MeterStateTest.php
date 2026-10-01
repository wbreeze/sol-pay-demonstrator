<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Auth\Binding;
use Newsprint\Chain\MeterState;
use PHPUnit\Framework\TestCase;
use SolPay\Core\BlockedKind;
use SolPay\Core\Meter;
use SolPay\Core\Site;
use SolPay\Core\TokenAccount;

/**
 * The questions the metering path asks of one read of a meter.
 *
 * The arithmetic is the library's (`Preflight`), and the library's own
 * conformance run checks it against the program. What is the site's here is
 * which question is asked with which clock, and what counts as the session
 * still holding the meter.
 */
final class MeterStateTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testAnOpenMeterWithRoomIsNotBlocked(): void
    {
        self::assertNull($this->state(used: 0)->blocked(self::NOW));
        self::assertSame(50, $this->state(used: 0)->itemsRemaining());
    }

    /**
     * SPEC §8.2's `Expired`, asked before the charge rather than learned from
     * a refused one. The program still meters at the expiry itself.
     */
    public function testAMeterPastItsExpiryIsBlockedAsExpired(): void
    {
        self::assertNull($this->state(expiry: self::NOW)->blocked(self::NOW), 'at the expiry, still open');
        self::assertSame(BlockedKind::Expired, $this->state(expiry: self::NOW)->blocked(self::NOW + 1)?->kind);
    }

    /** In the program's order: an expired meter at its limit is reported expired. */
    public function testExpiryIsAskedBeforeTheLimit(): void
    {
        $spent = $this->state(used: 500_000, expiry: self::NOW - 1);

        self::assertSame(BlockedKind::Expired, $spent->blocked(self::NOW)?->kind);
        self::assertSame(BlockedKind::LimitReached, $this->state(used: 500_000)->blocked(self::NOW)?->kind);
    }

    /** §7.4's table, last row: blocked at 0.49 against 0.50, because seven does not fit. */
    public function testSevenItemsDoNotFitWhereOneWould(): void
    {
        $state = $this->state(used: 490_000);

        self::assertNull($state->blocked(self::NOW, 1));
        self::assertSame(BlockedKind::LimitReached, $state->blocked(self::NOW, 7)?->kind);
    }

    public function testTheSessionHoldsTheMeterOnlyUnderItsOwnKeyAndFund(): void
    {
        $state = $this->state();

        self::assertTrue($state->binds(new Binding('MPDAfig', 'FPDAfig', 'BKEYfig')));
        self::assertFalse($state->binds(new Binding('MPDAfig', 'FPDAfig', 'BKEYcat')), 'renewed to another key');
        self::assertFalse($state->binds(new Binding('MPDAfig', 'FPDAcat', 'BKEYfig')), 'another fund');
        self::assertFalse($this->state(meter: false)->binds(new Binding('MPDAfig', 'FPDAfig', 'BKEYfig')), 'closed');
    }

    public function testTheBalanceIsTheFundsTokenAccount(): void
    {
        self::assertSame(80_000, $this->state()->balance());
        self::assertSame(0, $this->state(funds: false)->balance(), 'no token account is no money');
    }

    /**
     * §7.4's table: from 0.14 paid, seven more items accrue 0.07 and move
     * nothing; from 0.07 carried, seven more reach the threshold and move
     * 0.14. Only the second can be refused for a short fund, so only the
     * second is short.
     */
    public function testOnlyACallThatSettlesCanBeShortOfMoney(): void
    {
        $settled = $this->state(used: 140_000, paid: 140_000, balance: 0);
        self::assertFalse($settled->willSettle(7));
        self::assertSame(0, $settled->shortOfSettling(7), 'nothing moves, so an empty fund refuses nothing');

        $carrying = $this->state(used: 210_000, paid: 140_000, balance: 30_000);
        self::assertTrue($carrying->willSettle(7));
        self::assertSame(140_000, $carrying->wouldMove(7));
        self::assertSame(110_000, $carrying->shortOfSettling(7));

        self::assertSame(140_000, $this->state(used: 210_000, paid: 140_000, funds: false)->shortOfSettling(7), 'no token account is short by all of it');
    }

    /** The renewal floor: the site minimum, or the residue carried, whichever is more. */
    public function testTheLimitFloorCarriesTheResidue(): void
    {
        self::assertSame(500_000, $this->state(used: 70_000)->limitFloor());
        self::assertSame(500_000, $this->state(meter: false)->limitFloor());
    }

    private function state(int $used = 420_000, int $expiry = self::NOW + 3_600, bool $meter = true, bool $funds = true, int $paid = 0, int $balance = 80_000): MeterState
    {
        return new MeterState(
            meterAddress: 'MPDAfig',
            fund: 'FPDAfig',
            fundTokenAccount: 'FATAfig',
            meter: $meter ? new Meter('SPDAfig', 'FPDAfig', 'BKEYfig', $expiry, 500_000, $used, $paid, 254) : null,
            funds: $funds ? new TokenAccount('MINTfig', 'FPDAfig', $balance, null, 0) : null,
            site: new Site('AUTHfig', 'MINTfig', 'TRSYfig', 10_000, 100_000, 500_000, 255),
            decimals: 6,
        );
    }
}
