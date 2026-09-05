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
        self::assertSame(1, $store->sweepExpired());
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
            self::fail('the exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('meter failed', $e->getMessage());
        }

        // §7.3's order is meter, confirm, record, render — so a grant written
        // beside a failed meter must not survive.
        self::assertNull($store->liveGrant('PAYRfig', 'article-one'));
    }
}
