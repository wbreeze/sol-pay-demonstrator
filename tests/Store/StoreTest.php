<?php

declare(strict_types=1);

namespace Newsprint\Tests\Store;

use Newsprint\Store\Database;
use Newsprint\Store\Store;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §10.4 says the erasure claim is testable from outside and that an
 * erasure claim nothing checks will be wrong within two releases. This is
 * that check, plus the expiry the claim actually rests on (§10.4 q.1).
 */
final class StoreTest extends TestCase
{
    private int $now = 1_700_000_000;

    private function store(): Store
    {
        return new Store(Database::open(':memory:'), fn (): int => $this->now);
    }

    public function testAGrantIsLiveUntilItExpires(): void
    {
        $store = $this->store();
        $store->recordGrant('PAYRfig', 'why-approve-comes-first', 1_800);

        self::assertNotNull($store->liveGrant('PAYRfig', 'why-approve-comes-first'));

        $this->now += 1_799;
        self::assertNotNull($store->liveGrant('PAYRfig', 'why-approve-comes-first'));

        $this->now += 2;
        self::assertNull($store->liveGrant('PAYRfig', 'why-approve-comes-first'), 'thirty minutes, and no more');
        self::assertSame(['grants' => 1, 'sessions' => 0, 'payers' => 0, 'nonces' => 0], $store->sweepExpired());
    }

    public function testTheSweepDeletesExpiredSessionsAndOrphanedLockRows(): void
    {
        $store = $this->store();
        $store->createSession('PAYRfig', 3_600);
        $kept = $store->createSession('PAYRcat', 7_200);
        $store->withPayerLock('PAYRfig', static fn (): null => null);
        $store->withPayerLock('PAYRcat', static fn (): null => null);

        $this->now += 3_601;

        self::assertSame(
            ['grants' => 0, 'sessions' => 1, 'payers' => 1, 'nonces' => 0],
            $store->sweepExpired(),
            'the expired session goes, and so does the lock row nothing refers to',
        );
        self::assertSame('PAYRcat', $store->walletForSession($kept), 'and nobody else is touched');
    }

    public function testTheSweepDeletesExpiredNoncesAndKeepsLiveOnes(): void
    {
        $store = $this->store();
        $store->issueNonce(300);
        $this->now += 200;
        $live = $store->issueNonce(300);
        $this->now += 101;

        self::assertSame(['grants' => 0, 'sessions' => 0, 'payers' => 0, 'nonces' => 1], $store->sweepExpired());
        self::assertTrue($store->consumeNonce($live), 'a live challenge still works after a sweep');
    }

    public function testTheOldestExpiredRowSaysHowLongItHasWaited(): void
    {
        $store = $this->store();
        self::assertNull($store->oldestExpired(), 'an empty store has nothing waiting');

        $store->recordGrant('PAYRfig', 'article-one', 1_800);
        $store->createSession('PAYRfig', 600);
        $store->issueNonce(60);
        $this->now += 30;
        self::assertNull($store->oldestExpired(), 'nothing has expired yet');

        $this->now += 970;
        self::assertSame(400, $store->oldestExpired(), 'the session expired 400 s ago, and the older nonce does not count');

        $this->now += 1_000;
        self::assertSame(1_400, $store->oldestExpired(), 'the grant expired later than the session, so the session is still the oldest');

        $store->sweepExpired();
        self::assertNull($store->oldestExpired(), 'and a sweep clears both');
    }

    public function testAGrantIsPerArticleAndPerWallet(): void
    {
        $store = $this->store();
        $store->recordGrant('PAYRfig', 'article-one', 1_800);

        self::assertNull($store->liveGrant('PAYRfig', 'article-two'));
        self::assertNull($store->liveGrant('PAYRcat', 'article-one'));
    }

    public function testClosingPurgesTheReaderButNotTheFaucetLedger(): void
    {
        $store = $this->store();
        $session = $store->createSession('PAYRfig', 3_600);
        $store->recordGrant('PAYRfig', 'article-one', 1_800);
        $store->recordGrant('PAYRfig', 'article-two', 1_800);
        $store->recordGrant('PAYRcat', 'article-one', 1_800);
        $store->recordFaucet('PAYRfig', 'sig');

        self::assertSame(['sessions' => 1, 'grants' => 2], $store->eraseReader('PAYRfig'));

        self::assertNull($store->walletForSession($session), 'closing also signs the reader out');
        self::assertNull($store->liveGrant('PAYRfig', 'article-one'));
        self::assertNotNull($store->liveGrant('PAYRcat', 'article-one'), 'and touches nobody else');

        // §10.4 qualification 3, and §13.2's pass condition that the faucet
        // refuses rather than offering a button that will not work.
        self::assertTrue($store->faucetGranted('PAYRfig'));
        self::assertFalse($store->recordFaucet('PAYRfig'), 'one grant per wallet, close or no close');
    }

    public function testASignInNonceWorksOnceAndNotAfterItExpires(): void
    {
        $store = $this->store();

        $nonce = $store->issueNonce(300);
        self::assertTrue($store->consumeNonce($nonce));
        self::assertFalse($store->consumeNonce($nonce), 'a replayed nonce is refused');

        $expiring = $store->issueNonce(300);
        $this->now += 301;
        self::assertFalse($store->consumeNonce($expiring), 'a verifier that skips the expiry accepts a replay forever');
    }

    public function testTheCountSurvivesTheReadersErasure(): void
    {
        $store = $this->store();
        $store->recordGrant('PAYRfig', 'article-one', 1_800);
        $store->countPurchase('article-one');
        $store->eraseReader('PAYRfig');

        // §10.4 q.5, second condition: if deleting a reader's data changed
        // what the site can compute, it was never an aggregate.
        self::assertSame(1, $store->purchases('article-one'));
    }

    public function testThePayerLockRunsItsWorkAndCommits(): void
    {
        $store = $this->store();

        $result = $store->withPayerLock('PAYRfig', function () use ($store) {
            $store->recordGrant('PAYRfig', 'article-one', 1_800);

            return 'metered';
        });

        self::assertSame('metered', $result);
        self::assertNotNull($store->liveGrant('PAYRfig', 'article-one'));
    }

    public function testTheLockRollsBackWhenTheWorkThrows(): void
    {
        $store = $this->store();

        try {
            $store->withPayerLock('PAYRfig', function () use ($store): void {
                $store->recordGrant('PAYRfig', 'article-one', 1_800);

                throw new \RuntimeException('meter failed');
            });
            // No `self::fail()` here: the closure throws unconditionally, so a
            // line after the call is unreachable and says nothing. The catch
            // below is what asserts the exception came through.
        } catch (\RuntimeException $e) {
            self::assertSame('meter failed', $e->getMessage());
        }

        // §7.3's order is meter, confirm, record, render — so a grant written
        // beside a failed meter must not survive.
        self::assertNull($store->liveGrant('PAYRfig', 'article-one'));
    }
}
