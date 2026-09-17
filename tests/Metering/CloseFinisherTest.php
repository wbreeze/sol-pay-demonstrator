<?php

declare(strict_types=1);

namespace Newsprint\Tests\Metering;

use Newsprint\Metering\CloseFinisher;
use Newsprint\Store\Database;
use Newsprint\Store\Store;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §10.4: a close that lands after the server stopped waiting is still
 * followed by the erasure.
 *
 * Found 2026-09-16 while writing *Two orderings that disagree on purpose*:
 * the erasure ran only in the request that saw the contract gone, and that
 * request gave up after twenty seconds. Each case below is one branch of
 * {@see CloseFinisher::finish()}. The chain is a closure, which also counts
 * how often it is asked.
 */
final class CloseFinisherTest extends TestCase
{
    private const SETTLE_S = 180;

    private int $now = 1_700_000_000;

    private int $asked = 0;

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store(Database::open(':memory:'), fn (): int => $this->now);
        $this->store->createSession('PAYRfig', 43_200);
        $this->store->recordGrant('PAYRfig', 'article-one', 1_800);
    }

    public function testWithNoNoteTheChainIsNotAsked(): void
    {
        self::assertNull($this->finish(false));
        self::assertSame(0, $this->asked, 'a reader with no pending close costs no chain call');
        self::assertTrue($this->stillHeld());
    }

    public function testALateCloseThatLandedIsErased(): void
    {
        $this->store->recordPendingClose('PAYRfig', 'sigclose', 43_200);
        $this->now += 25;

        self::assertSame(['sessions' => 1, 'grants' => 1], $this->finish(false));
        self::assertFalse($this->stillHeld(), 'session and grant are gone');
        self::assertNull($this->store->pendingClose('PAYRfig'), 'and so is the note');
    }

    public function testAContractStillThereInsideTheWindowKeepsWaiting(): void
    {
        $this->store->recordPendingClose('PAYRfig', 'sigclose', 43_200);
        $this->now += self::SETTLE_S - 1;

        self::assertNull($this->finish(true));
        self::assertTrue($this->stillHeld(), 'nothing is erased while the close may still land');
        self::assertNotNull($this->store->pendingClose('PAYRfig'), 'and the note stays');
    }

    public function testAContractStillThereAfterTheWindowDropsTheNote(): void
    {
        $this->store->recordPendingClose('PAYRfig', 'sigclose', 43_200);
        $this->now += self::SETTLE_S;

        self::assertNull($this->finish(true));
        self::assertTrue($this->stillHeld(), 'a close that failed erases nothing');
        self::assertNull($this->store->pendingClose('PAYRfig'), 'and the next request is not asked again');
    }

    public function testAnUnreadableChainErasesNothingAndKeepsTheNote(): void
    {
        $this->store->recordPendingClose('PAYRfig', 'sigclose', 43_200);
        // Past the window, but inside the grant's thirty minutes.
        $this->now += self::SETTLE_S * 5;

        self::assertNull($this->finish(null));
        self::assertTrue($this->stillHeld(), 'nothing is erased on a maybe');
        self::assertNotNull($this->store->pendingClose('PAYRfig'), 'not even a late maybe');
    }

    public function testOnlyTheClosingWalletIsErased(): void
    {
        $this->store->createSession('PAYRcat', 43_200);
        $this->store->recordGrant('PAYRcat', 'article-one', 1_800);
        $this->store->recordPendingClose('PAYRfig', 'sigclose', 43_200);

        $this->finish(false);

        self::assertNotNull($this->store->liveGrant('PAYRcat', 'article-one'));
    }

    /** @return array{sessions: int, grants: int}|null */
    private function finish(?bool $contractExists): ?array
    {
        return (new CloseFinisher($this->store, self::SETTLE_S))->finish(
            'PAYRfig',
            function () use ($contractExists): ?bool {
                ++$this->asked;

                return $contractExists;
            },
        );
    }

    private function stillHeld(): bool
    {
        return $this->store->liveGrant('PAYRfig', 'article-one') !== null;
    }
}
