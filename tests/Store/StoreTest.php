<?php

declare(strict_types=1);

namespace Newsprint\Tests\Store;

use Newsprint\Auth\Binding;
use Newsprint\Metering\ChargeState;
use Newsprint\Pay\SetupAnswers;
use Newsprint\Store\Database;
use Newsprint\Store\Store;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §10.4 says the erasure claim is testable from outside and that an
 * erasure claim nothing checks will be wrong within two releases. This is
 * that check, plus the expiry the claim actually rests on (§10.4
 * qualification 1).
 *
 * The meters, funds and keys are stand-ins named for their roles, the way the
 * inspector names them. The store never decodes an address.
 */
final class StoreTest extends TestCase
{
    private int $now = 1_700_000_000;

    private function store(): Store
    {
        return new Store(Database::open(':memory:'), fn (): int => $this->now);
    }

    private function binding(string $meter = 'MPDAfig', string $key = 'BKEYfig'): Binding
    {
        return new Binding($meter, 'FPDA'.substr($meter, 4), $key);
    }

    /** @return array{grants: int, sessions: int, meters: int, nonces: int, setups: int, closes: int} */
    private static function swept(int $grants = 0, int $sessions = 0, int $meters = 0, int $nonces = 0, int $setups = 0, int $closes = 0): array
    {
        return compact('grants', 'sessions', 'meters', 'nonces', 'setups', 'closes');
    }

    public function testASessionHoldsTheMeterItsFundAndTheProvenKey(): void
    {
        $store = $this->store();
        $id = $store->createSession(new Binding('MPDAfig', 'FPDAfig', 'BKEYfig'), 3_600);

        $binding = $store->bindingForSession($id);
        self::assertNotNull($binding);
        self::assertSame(['MPDAfig', 'FPDAfig', 'BKEYfig'], [$binding->meter, $binding->fund, $binding->key]);

        $this->now += 3_600;
        self::assertNull($store->bindingForSession($id), 'and not past its time');
    }

    /**
     * SPEC §5.3: a renewal from another device names that device's key, and
     * the read that finds it ends the sessions under the old key. The other
     * device's session, on the same meter, is not this one's to end.
     */
    public function testEndingSessionsForAKeyLeavesTheMetersOtherKeyAlone(): void
    {
        $store = $this->store();
        $here = $store->createSession($this->binding(key: 'BKEYold'), 3_600);
        $there = $store->createSession($this->binding(key: 'BKEYnew'), 3_600);

        self::assertSame(1, $store->endSessions($this->binding(key: 'BKEYold')));

        self::assertNull($store->bindingForSession($here));
        self::assertNotNull($store->bindingForSession($there));
    }

    public function testAGrantIsLiveUntilItExpires(): void
    {
        $store = $this->store();
        $store->recordGrant('MPDAfig', 'why-approve-comes-first', 1_800);

        self::assertNotNull($store->liveGrant('MPDAfig', 'why-approve-comes-first'));

        $this->now += 1_799;
        self::assertNotNull($store->liveGrant('MPDAfig', 'why-approve-comes-first'));

        $this->now += 2;
        self::assertNull($store->liveGrant('MPDAfig', 'why-approve-comes-first'), 'thirty minutes, and no more');
        self::assertSame(self::swept(grants: 1), $store->sweepExpired());
    }

    public function testTheSweepDeletesExpiredSessionsAndOrphanedLockRows(): void
    {
        $store = $this->store();
        $store->createSession($this->binding('MPDAfig'), 3_600);
        $kept = $store->createSession($this->binding('MPDAcat'), 7_200);
        $store->withMeterLock('MPDAfig', static fn (): null => null);
        $store->withMeterLock('MPDAcat', static fn (): null => null);

        $this->now += 3_601;

        self::assertSame(
            self::swept(sessions: 1, meters: 1),
            $store->sweepExpired(),
            'the expired session goes, and so does the lock row nothing refers to',
        );
        self::assertSame('MPDAcat', $store->bindingForSession($kept)?->meter, 'and nobody else is touched');
    }

    public function testTheSweepDeletesExpiredNoncesAndKeepsLiveOnes(): void
    {
        $store = $this->store();
        $store->issueNonce(300);
        $this->now += 200;
        $live = $store->issueNonce(300);
        $this->now += 101;

        self::assertSame(self::swept(nonces: 1), $store->sweepExpired());
        self::assertTrue($store->consumeNonce($live), 'a live nonce still works after a sweep');
    }

    /**
     * SPEC §5.2: thirty-two random bytes, forgotten at their first
     * presentation. The second presentation is the replay, and it must find
     * nothing whether the first one's proof passed or not, because the store
     * cannot know which.
     */
    public function testANonceIsGoodOnceAndForgottenAtItsFirstPresentation(): void
    {
        $store = $this->store();

        $nonce = $store->issueNonce(300);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $nonce, '32 bytes, hex');
        self::assertTrue($store->consumeNonce($nonce));
        self::assertFalse($store->consumeNonce($nonce), 'a replayed nonce is refused');

        $expiring = $store->issueNonce(300);
        $this->now += 301;
        self::assertFalse($store->consumeNonce($expiring), 'a nonce past five minutes is refused');
        self::assertSame(self::swept(), $store->sweepExpired(), 'and was forgotten when it was presented, not left for the sweep');

        self::assertFalse($store->consumeNonce('not one this server issued'));
    }

    public function testThePendingSetupsTableIsSweptAfterItsWindow(): void
    {
        $store = $this->store();
        $store->createPendingSetup(new SetupAnswers(SetupAnswers::SETUP, 0, 500_000, 500_000, 'day', 'BKEYfig'), 600);

        self::assertSame(self::swept(), $store->sweepExpired());
        $this->now += 600;
        self::assertSame(0, $store->oldestExpired(), 'an expired setup is a row about a reader, waiting');
        self::assertSame(self::swept(setups: 1), $store->sweepExpired());
    }

    /**
     * SPEC §6.3: a pending setup holds the panel's answers and, once the
     * wallet has asked, the wallet's address, for ten minutes or until
     * *continue*. Its id is the only thing the wallet's link carries.
     */
    public function testAPendingSetupHoldsItsAnswersAndThenTheWallet(): void
    {
        $store = $this->store();
        $answers = new SetupAnswers(SetupAnswers::SETUP, 3, 500_000, 600_000, 'week', 'BKEYfig');
        $id = $store->createPendingSetup($answers, 600);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id, '128 random bits');
        $held = $store->pendingSetup($id);
        self::assertNotNull($held);
        self::assertSame($answers->toArray(), $held['answers']->toArray());
        self::assertNull($held['wallet'], 'no wallet until the wallet asks');

        $store->recordSetupWallet($id, 'RDRfig');
        self::assertSame('RDRfig', $store->pendingSetup($id)['wallet'] ?? null);

        $store->forgetPendingSetup($id);
        self::assertNull($store->pendingSetup($id), 'gone at continue');

        $late = $store->createPendingSetup($answers, 600);
        $this->now += 600;
        self::assertNull($store->pendingSetup($late), 'and dead after ten minutes');
    }

    public function testAPendingCloseIsKeptUntilDroppedErasedOrExpired(): void
    {
        $store = $this->store();
        $store->createSession($this->binding(), 43_200);
        self::assertNull($store->pendingClose('MPDAfig'));

        $store->recordPendingClose('MPDAfig', 'sigclose', 43_200);
        self::assertSame(['signature' => 'sigclose', 'sent_at' => $this->now], $store->pendingClose('MPDAfig'));

        $store->dropPendingClose('MPDAfig');
        self::assertNull($store->pendingClose('MPDAfig'), 'dropped when the close cannot land');

        $store->recordPendingClose('MPDAfig', 'sigclose', 43_200);
        $store->eraseMeter('MPDAfig');
        self::assertNull($store->pendingClose('MPDAfig'), 'and it goes with the erasure it was waiting for');

        $store->recordPendingClose('MPDAfig', 'sigclose', 600);
        $this->now += 600;
        self::assertNull($store->pendingClose('MPDAfig'), 'and it stops counting when it expires');
    }

    public function testTheSweepDeletesExpiredAndOrphanedPendingCloses(): void
    {
        $store = $this->store();
        $store->createSession($this->binding('MPDAfig'), 43_200);
        $store->recordPendingClose('MPDAfig', 'sigfig', 600);
        $store->createSession($this->binding('MPDAcat'), 43_200);
        $store->recordPendingClose('MPDAcat', 'sigcat', 43_200);
        $store->recordPendingClose('MPDAdog', 'sigdog', 43_200);

        $this->now += 600;

        self::assertSame(
            self::swept(closes: 2),
            $store->sweepExpired(),
            'the expired note goes, and so does the note whose meter has nothing left to erase',
        );
        self::assertNotNull($store->pendingClose('MPDAcat'), 'a live note for a live session stays');
    }

    public function testTheOldestExpiredRowSaysHowLongItHasWaited(): void
    {
        $store = $this->store();
        self::assertNull($store->oldestExpired(), 'an empty store has nothing waiting');

        $store->recordGrant('MPDAfig', 'article-one', 1_800);
        $store->createSession($this->binding(), 600);
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

    public function testAGrantIsPerArticleAndPerMeter(): void
    {
        $store = $this->store();
        $store->recordGrant('MPDAfig', 'article-one', 1_800);

        self::assertNull($store->liveGrant('MPDAfig', 'article-two'));
        self::assertNull($store->liveGrant('MPDAcat', 'article-one'));
    }

    public function testClosingPurgesTheMeterButNotTheFaucetLedger(): void
    {
        $store = $this->store();
        $session = $store->createSession($this->binding('MPDAfig'), 3_600);
        $store->recordGrant('MPDAfig', 'article-one', 1_800);
        $store->recordGrant('MPDAfig', 'article-two', 1_800);
        $store->recordGrant('MPDAcat', 'article-one', 1_800);
        $store->recordFaucet('RDRfig', 'sig');

        self::assertSame(['sessions' => 1, 'grants' => 2], $store->eraseMeter('MPDAfig'));

        self::assertNull($store->bindingForSession($session), 'closing also ends the session');
        self::assertNull($store->liveGrant('MPDAfig', 'article-one'));
        self::assertNotNull($store->liveGrant('MPDAcat', 'article-one'), 'and touches nobody else');

        // §10.4 qualification 4, and §13.2's pass condition that the faucet
        // refuses rather than offering a button that will not work.
        self::assertTrue($store->faucetGranted('RDRfig'));
        self::assertFalse($store->recordFaucet('RDRfig'), 'one grant per address, close or no close');
    }

    public function testTheCountSurvivesTheMetersErasure(): void
    {
        $store = $this->store();
        $store->recordGrant('MPDAfig', 'article-one', 1_800);
        $store->countPurchase('article-one');
        $store->eraseMeter('MPDAfig');

        // §10.4's aggregates, second condition: if deleting a reader's data
        // changed what the site can compute, it was never an aggregate.
        self::assertSame(1, $store->purchases('article-one'));
    }

    public function testTheMeterLockRunsItsWorkAndCommits(): void
    {
        $store = $this->store();

        $result = $store->withMeterLock('MPDAfig', function () use ($store) {
            $store->recordGrant('MPDAfig', 'article-one', 1_800);

            return 'metered';
        });

        self::assertSame('metered', $result);
        self::assertNotNull($store->liveGrant('MPDAfig', 'article-one'));
    }

    public function testTheLockRollsBackWhenTheWorkThrows(): void
    {
        $store = $this->store();

        try {
            $store->withMeterLock('MPDAfig', function () use ($store): void {
                $store->recordGrant('MPDAfig', 'article-one', 1_800);

                throw new \RuntimeException('meter failed');
            });
            // No `self::fail()` here: the closure throws unconditionally, so a
            // line after the call is unreachable and says nothing. The catch
            // below is what asserts the exception came through.
        } catch (\RuntimeException $e) {
            self::assertSame('meter failed', $e->getMessage());
        }

        // §7.3's order is meter, record, render, confirm — and a grant
        // written beside a meter that threw must not survive.
        self::assertNull($store->liveGrant('MPDAfig', 'article-one'));
    }

    public function testAPendingChargeIsSettledOnceAndOnlyForItsOwnSignature(): void
    {
        $store = $this->store();
        $store->recordGrant('MPDAfig', 'article-one', 1_800, 'sigone', ChargeState::Pending);

        self::assertFalse($store->settleCharge('MPDAfig', 'article-one', 'sigother', ChargeState::Confirmed));
        self::assertTrue($store->settleCharge('MPDAfig', 'article-one', 'sigone', ChargeState::Confirmed));
        self::assertFalse($store->settleCharge('MPDAfig', 'article-one', 'sigone', ChargeState::Refused), 'once');
        self::assertSame(ChargeState::Confirmed, $store->liveGrant('MPDAfig', 'article-one')['charge'] ?? null);
    }

    /**
     * The fund design starts the schema fresh (`Database::VERSION`). A copy
     * that ran the delegate design loses its sessions, grants and lock rows,
     * all keyed by wallet, and keeps the two tables that are not reader data
     * the redesign changed: the faucet ledger, carried to its new key, and the
     * purchase counts.
     */
    public function testTheDelegateDesignsDatabaseStartsFreshAndKeepsTheLedger(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, wallet TEXT NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE grants (wallet TEXT NOT NULL, article TEXT NOT NULL, granted_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL, signature TEXT, confirmed INTEGER NOT NULL DEFAULT 1,
            charge TEXT NOT NULL DEFAULT \'confirmed\', PRIMARY KEY (wallet, article))');
        $pdo->exec('CREATE TABLE payers (wallet TEXT PRIMARY KEY, updated_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE signin_nonces (nonce TEXT PRIMARY KEY, issued_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, used_at INTEGER, input TEXT)');
        $pdo->exec('CREATE TABLE pending_closes (wallet TEXT PRIMARY KEY, signature TEXT NOT NULL, sent_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE faucet_ledger (wallet TEXT PRIMARY KEY, granted_at INTEGER NOT NULL, signature TEXT)');
        $pdo->exec('CREATE TABLE article_purchases (article TEXT PRIMARY KEY, purchases INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO sessions VALUES ('s', 'PAYRfig', {$this->now}, {$this->now} + 3600)");
        $pdo->exec("INSERT INTO grants (wallet, article, granted_at, expires_at) VALUES ('PAYRfig', 'article-one', {$this->now}, {$this->now} + 1800)");
        $pdo->exec("INSERT INTO faucet_ledger VALUES ('PAYRfig', {$this->now}, 'sigfaucet')");
        $pdo->exec("INSERT INTO article_purchases VALUES ('article-one', 3)");

        Database::migrate($pdo);
        $store = new Store($pdo, fn (): int => $this->now);

        self::assertSame(Database::VERSION, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        self::assertNull($store->bindingForSession('s'));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM grants')->fetchColumn());
        self::assertTrue($store->faucetGranted('PAYRfig'), 'the address keeps its one grant');
        self::assertSame(3, $store->purchases('article-one'));

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(
            ['article_purchases', 'faucet_ledger', 'grants', 'meters', 'nonces', 'pending_closes', 'pending_setups', 'sessions'],
            $tables,
            'the delegate design\'s own tables are gone',
        );

        Database::migrate($pdo);
        self::assertTrue($store->faucetGranted('PAYRfig'), 'and a second open changes nothing');
    }

    /**
     * SPEC §5.4: the close the server compiled waits on the session for the
     * page's signature, and only the newest one can be signed.
     */
    public function testTheCloseMessageWaitsOnItsSession(): void
    {
        $store = $this->store();
        $id = $store->createSession($this->binding(), 3_600);
        self::assertNull($store->closeMessage($id));

        $store->keepCloseMessage($id, "\x02first");
        $store->keepCloseMessage($id, "\x02second");
        self::assertSame("\x02second", $store->closeMessage($id), 'a second prepare replaces the first');

        $other = $store->createSession($this->binding('MPDAcat'), 3_600);
        self::assertNull($store->closeMessage($other), 'and it is this session\'s alone');

        $this->now += 3_600;
        self::assertNull($store->closeMessage($id), 'not past the session\'s time');
    }

    /** A version-2 file, as slice 1 left it, gains the column and keeps its rows. */
    public function testAVersionTwoDatabaseGainsTheCloseMessage(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, meter TEXT NOT NULL, fund TEXT NOT NULL, key TEXT NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE faucet_ledger (address TEXT PRIMARY KEY, granted_at INTEGER NOT NULL, signature TEXT)');
        $pdo->exec("INSERT INTO sessions VALUES ('s', 'MPDAfig', 'FPDAfig', 'BKEYfig', {$this->now}, {$this->now} + 3600)");
        $pdo->exec("INSERT INTO faucet_ledger VALUES ('RDRfig', {$this->now}, 'sig')");
        $pdo->exec('PRAGMA user_version = 2');

        Database::migrate($pdo);
        $store = new Store($pdo, fn (): int => $this->now);

        self::assertSame(Database::VERSION, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        self::assertSame('MPDAfig', $store->bindingForSession('s')?->meter, 'the session survives');
        self::assertTrue($store->faucetGranted('RDRfig'));
        $store->keepCloseMessage('s', 'message');
        self::assertSame('message', $store->closeMessage('s'));
    }
}
