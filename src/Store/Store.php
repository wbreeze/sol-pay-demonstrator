<?php

declare(strict_types=1);

namespace Newsprint\Store;

use Newsprint\Auth\Binding;
use Newsprint\Metering\ChargeState;
use Newsprint\Pay\SetupAnswers;
use PDO;

/**
 * Every read and write the site makes about a reader, in one class, so that
 * SPEC §10.2's promise — "the actual stores, enumerated, each one traceable to
 * a line in the code" — is checkable by reading one file.
 *
 * **Everything about a reader is keyed by the meter** (SPEC §10.4). The fund
 * design knows a browser by the meter its key answers to, so the meter's
 * address is the one name the session, the grants, the lock row and a pending
 * close share, and closing the meter erases by that name. The faucet ledger is
 * the exception, keyed by the address the faucet sent to, because it is not
 * about a meter at all.
 *
 * Time is injected rather than taken from `time()` so expiry is testable
 * without sleeping. Everything here takes seconds since the epoch.
 */
final class Store
{
    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock
     */
    public function __construct(
        private readonly PDO $pdo,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    // ---- §5.3 session ----------------------------------------------------

    /**
     * Bind a session to a meter, after a key proof (SPEC §5.3).
     *
     * 256 bits from the CSPRNG. The cookie carries this and nothing else,
     * which is what entitles the privacy page to call it strictly necessary.
     */
    public function createSession(Binding $binding, int $ttlSeconds): string
    {
        $id = bin2hex(random_bytes(32));
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO sessions (id, meter, fund, key, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $binding->meter, $binding->fund, $binding->key, $now, $now + $ttlSeconds]);

        return $id;
    }

    /** The meter, fund and key a live session holds, or null. */
    public function bindingForSession(string $id): ?Binding
    {
        $stmt = $this->pdo->prepare('SELECT meter, fund, key FROM sessions WHERE id = ? AND expires_at > ?');
        $stmt->execute([$id, $this->now()]);
        $row = $stmt->fetch();

        return $row === false ? null : new Binding((string) $row['meter'], (string) $row['fund'], (string) $row['key']);
    }

    public function destroySession(string $id): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
    }

    /**
     * Keep the `close_meter` message compiled for this session, until the
     * page returns it signed (SPEC §5.4). A second prepare replaces the first,
     * so only the newest message can be signed and sent.
     */
    public function keepCloseMessage(string $id, string $message): void
    {
        $this->pdo->prepare('UPDATE sessions SET close_message = ? WHERE id = ?')
            ->execute([base64_encode($message), $id]);
    }

    /** The message `keepCloseMessage()` kept for a live session, or null. */
    public function closeMessage(string $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT close_message FROM sessions WHERE id = ? AND expires_at > ?');
        $stmt->execute([$id, $this->now()]);
        $kept = $stmt->fetchColumn();
        if (!is_string($kept)) {
            return null;
        }
        $message = base64_decode($kept, true);

        return $message === false ? null : $message;
    }

    /**
     * End every session that holds this meter under this key (SPEC §5.3).
     *
     * By meter and key rather than by session id, because the read that finds
     * the meter renewed to another key is a fact about every browser holding
     * the old key, and it is found on whichever request reads first. Sessions
     * on the same meter under the new key are the other device's, and stay.
     */
    public function endSessions(Binding $binding): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE meter = ? AND key = ?');
        $stmt->execute([$binding->meter, $binding->key]);

        return $stmt->rowCount();
    }

    // ---- §5.2 proof nonces -----------------------------------------------

    /**
     * Issue a nonce for a key proof: 32 random bytes, hex-encoded, stored with
     * the time it was issued.
     */
    public function issueNonce(int $ttlSeconds): string
    {
        $nonce = bin2hex(random_bytes(32));
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO nonces (nonce, issued_at, expires_at) VALUES (?, ?, ?)'
        )->execute([$nonce, $now, $now + $ttlSeconds]);

        return $nonce;
    }

    /**
     * Forget the nonce, and say whether it was one this server issued and has
     * not let expire.
     *
     * Forgotten at this first presentation whether the proof then passes or
     * not (SPEC §5.2). A proof accepted twice can be replayed by whoever
     * copies it, and each article the replayer reads is charged to the
     * reader's fund, so the nonce must be gone before anything is checked.
     * One `DELETE` decides both halves: a second presentation finds no row.
     */
    public function consumeNonce(string $nonce): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM nonces WHERE nonce = ? AND expires_at > ?');
        $stmt->execute([$nonce, $this->now()]);
        $live = $stmt->rowCount() === 1;

        // An expired nonce presented is forgotten too, rather than left for the
        // sweep: its presentation is its use.
        $this->pdo->prepare('DELETE FROM nonces WHERE nonce = ?')->execute([$nonce]);

        return $live;
    }

    // ---- §6.3 pending setups ---------------------------------------------

    /**
     * Record a setup the panel started: 128 random bits as its id, the only
     * thing the wallet's link carries (SPEC §12.3).
     */
    public function createPendingSetup(SetupAnswers $answers, int $ttlSeconds): string
    {
        $id = bin2hex(random_bytes(16));
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO pending_setups (id, kind, key, answers, fund, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $answers->kind,
            $answers->key,
            (string) json_encode($answers->toArray()),
            $answers->fund,
            $now,
            $now + $ttlSeconds,
        ]);

        return $id;
    }

    /** @return array{answers: SetupAnswers, wallet: ?string}|null a live pending setup */
    public function pendingSetup(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT answers, wallet FROM pending_setups WHERE id = ? AND expires_at > ?');
        $stmt->execute([$id, $this->now()]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $answers = json_decode((string) $row['answers'], true);

        return is_array($answers) ? [
            'answers' => SetupAnswers::fromArray($answers),
            'wallet' => $row['wallet'] === null ? null : (string) $row['wallet'],
        ] : null;
    }

    /**
     * The wallet that asked for the transaction (SPEC §6.3 step 4). Held for
     * the setup's ten minutes at most, and gone at *continue*.
     */
    public function recordSetupWallet(string $id, string $wallet): void
    {
        $this->pdo->prepare('UPDATE pending_setups SET wallet = ? WHERE id = ?')->execute([$wallet, $id]);
    }

    public function forgetPendingSetup(string $id): void
    {
        $this->pdo->prepare('DELETE FROM pending_setups WHERE id = ?')->execute([$id]);
    }

    // ---- §7.1 view grants ------------------------------------------------

    /** @return array{granted_at: int, expires_at: int, signature: ?string, charge: ChargeState}|null */
    public function liveGrant(string $meter, string $article): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT granted_at, expires_at, signature, charge FROM grants
             WHERE meter = ? AND article = ? AND expires_at > ?'
        );
        $stmt->execute([$meter, $article, $this->now()]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'granted_at' => (int) $row['granted_at'],
            'expires_at' => (int) $row['expires_at'],
            'signature' => $row['signature'] === null ? null : (string) $row['signature'],
            // An unrecognised word is read as the one state that asks the
            // chain again, not as a verdict nobody wrote.
            'charge' => ChargeState::tryFrom((string) $row['charge']) ?? ChargeState::Pending,
        ];
    }

    /**
     * Recorded before the body is rendered (§7.3), so a render failure still
     * leaves the reader holding what they paid for.
     *
     * Since 2026-09-17 an article charge is recorded `Pending`: the body is
     * served once the endpoint has accepted the transaction, and a later
     * request finds out whether it landed ({@see settleCharge()}).
     */
    public function recordGrant(
        string $meter,
        string $article,
        int $ttlSeconds,
        ?string $signature = null,
        ChargeState $charge = ChargeState::Confirmed,
    ): void {
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO grants (meter, article, granted_at, expires_at, signature, charge)
             VALUES (:m, :a, :g, :e, :s, :ch)
             ON CONFLICT (meter, article) DO UPDATE SET
                 granted_at = :g, expires_at = :e, signature = :s, charge = :ch'
        )->execute([
            ':m' => $meter,
            ':a' => $article,
            ':g' => $now,
            ':e' => $now + $ttlSeconds,
            ':s' => $signature,
            ':ch' => $charge->value,
        ]);
    }

    /**
     * Write what became of a pending charge, once.
     *
     * Conditional on the row still being pending *for that signature*, so two
     * requests that found out at the same moment cannot disagree in the table,
     * and a grant renewed by a later charge is never overwritten by news about
     * an older one. There is no lock around this and none is needed: the
     * answer comes from the chain, and the second writer would write the same
     * thing or nothing.
     *
     * True when this call wrote it.
     */
    public function settleCharge(string $meter, string $article, string $signature, ChargeState $charge): bool
    {
        if ($charge === ChargeState::Pending) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE grants SET charge = :ch
             WHERE meter = :m AND article = :a AND signature = :s AND charge = 'pending'"
        );
        $stmt->execute([
            ':ch' => $charge->value,
            ':m' => $meter,
            ':a' => $article,
            ':s' => $signature,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * How many live grants this meter holds.
     *
     * Not a reading history being read — a count, and the only place it is
     * used is the close confirmation, where §10.4 qualification 3 requires
     * the reader to be told *before* they click that closing costs them the
     * articles they have already paid for. Saying "one article" when it is
     * three would be a disclosure that misleads.
     */
    public function liveGrantCount(string $meter): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS n FROM grants WHERE meter = ? AND expires_at > ?');
        $stmt->execute([$meter, $this->now()]);

        return (int) $stmt->fetch()['n'];
    }

    /**
     * Delete every row whose window has passed, and the lock rows nothing
     * refers to any more (§10.4 qualification 1).
     *
     * Expiry by itself deletes nothing. `liveGrant()` and `bindingForSession()`
     * skip an expired row, and the row stays. Until 2026-09-16 nothing called
     * this method, so every lapsed grant stayed in the table until the reader
     * closed the meter. Those rows add up to a reading history, and the
     * privacy page promises a receipt that is gone in thirty minutes. The
     * metering path calls this method inside the meter lock; see
     * {@see \Newsprint\Metering\Meter::forArticle()}. `bin/sweep` calls it on
     * a schedule, for the hours when nobody buys anything.
     *
     * A `meters` row goes once its meter has no session and no grant left.
     * The row exists only to be locked.
     *
     * A nonce goes after five minutes whether or not it was presented, and a
     * pending setup after ten. A pending-close note goes when it expires, or
     * once its meter has no session and no grant left, since the note then
     * protects nothing.
     *
     * @return array{grants: int, sessions: int, meters: int, nonces: int, setups: int, closes: int}
     */
    public function sweepExpired(): array
    {
        $now = $this->now();

        $grants = $this->pdo->prepare('DELETE FROM grants WHERE expires_at <= ?');
        $grants->execute([$now]);

        $sessions = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at <= ?');
        $sessions->execute([$now]);

        $meters = $this->pdo->prepare(
            'DELETE FROM meters
             WHERE meter NOT IN (SELECT meter FROM sessions)
               AND meter NOT IN (SELECT meter FROM grants)'
        );
        $meters->execute();

        $nonces = $this->pdo->prepare('DELETE FROM nonces WHERE expires_at <= ?');
        $nonces->execute([$now]);

        $setups = $this->pdo->prepare('DELETE FROM pending_setups WHERE expires_at <= ?');
        $setups->execute([$now]);

        $closes = $this->pdo->prepare(
            'DELETE FROM pending_closes
             WHERE expires_at <= ?
                OR (meter NOT IN (SELECT meter FROM sessions)
                    AND meter NOT IN (SELECT meter FROM grants))'
        );
        $closes->execute([$now]);

        return [
            'grants' => $grants->rowCount(),
            'sessions' => $sessions->rowCount(),
            'meters' => $meters->rowCount(),
            'nonces' => $nonces->rowCount(),
            'setups' => $setups->rowCount(),
            'closes' => $closes->rowCount(),
        ];
    }

    /**
     * How long the oldest expired row about a reader has waited for a sweep,
     * in seconds, or null when nothing is waiting.
     *
     * `GET /health` reports this number. With `bin/sweep` on its schedule, the
     * number stays below `sweep_every_s`. A number that keeps growing means the
     * schedule is not running, and the privacy page's promise is slipping.
     * Nonces are left out, because they name no reader.
     */
    public function oldestExpired(): ?int
    {
        $now = $this->now();
        $stmt = $this->pdo->prepare(
            'SELECT MIN(expires_at) AS oldest FROM (
                 SELECT expires_at FROM grants WHERE expires_at <= :now
                 UNION ALL
                 SELECT expires_at FROM sessions WHERE expires_at <= :now
                 UNION ALL
                 SELECT expires_at FROM pending_setups WHERE expires_at <= :now
             )'
        );
        $stmt->execute([':now' => $now]);
        $oldest = $stmt->fetch()['oldest'] ?? null;

        return $oldest === null ? null : $now - (int) $oldest;
    }

    // ---- §10.4 erasure ---------------------------------------------------

    /**
     * Closing the meter purges the site's record of the reader: sessions,
     * grants, the lock row, and the note of a close still waiting to be
     * confirmed. The faucet ledger deliberately survives, for the published
     * reason in §10.4 qualification 4.
     *
     * Returns what went, because §10.4 says this is testable from outside and
     * the inspector shows the DELETE happening rather than asserting it.
     *
     * @return array{sessions: int, grants: int}
     */
    public function eraseMeter(string $meter): array
    {
        $sessions = $this->pdo->prepare('DELETE FROM sessions WHERE meter = ?');
        $sessions->execute([$meter]);
        $grants = $this->pdo->prepare('DELETE FROM grants WHERE meter = ?');
        $grants->execute([$meter]);
        $this->pdo->prepare('DELETE FROM meters WHERE meter = ?')->execute([$meter]);
        $this->pdo->prepare('DELETE FROM pending_closes WHERE meter = ?')->execute([$meter]);

        return ['sessions' => $sessions->rowCount(), 'grants' => $grants->rowCount()];
    }

    // ---- §10.4 a close the chain had not confirmed yet -------------------

    /**
     * Remember that this meter's close was sent and not yet seen to land.
     *
     * The close route waits a bounded time and then reads the meter. When the
     * account is still there, nothing is deleted, and the close may still land
     * a few seconds later. Without this note, the request that comes after
     * cannot tell a meter just closed from one closed long ago, and the
     * erasure never runs. {@see \Newsprint\Metering\CloseFinisher} reads it.
     */
    public function recordPendingClose(string $meter, string $signature, int $ttlSeconds): void
    {
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO pending_closes (meter, signature, sent_at, expires_at)
             VALUES (:m, :s, :n, :e)
             ON CONFLICT (meter) DO UPDATE SET signature = :s, sent_at = :n, expires_at = :e'
        )->execute([':m' => $meter, ':s' => $signature, ':n' => $now, ':e' => $now + $ttlSeconds]);
    }

    /** @return array{signature: string, sent_at: int}|null */
    public function pendingClose(string $meter): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT signature, sent_at FROM pending_closes WHERE meter = ? AND expires_at > ?'
        );
        $stmt->execute([$meter, $this->now()]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'signature' => (string) $row['signature'],
            'sent_at' => (int) $row['sent_at'],
        ];
    }

    /** The close did not land and no longer can. */
    public function dropPendingClose(string $meter): void
    {
        $this->pdo->prepare('DELETE FROM pending_closes WHERE meter = ?')->execute([$meter]);
    }

    // ---- §7.2 one charge at a time per meter -----------------------------

    /**
     * Run `$work` with this meter serialized against every other request for
     * the same meter.
     *
     * `BEGIN IMMEDIATE` takes SQLite's write lock at the start rather than on
     * first write, which is the point: the read, the preflight, the charge and
     * the grant have to be inside one critical section, and a deferred
     * transaction would upgrade halfway through and lose the race it exists
     * to prevent. The `meters` row is touched so the lock and the state it
     * guards are the same object (§12.5).
     *
     * Note the lock is database-wide (see {@see Database}), so this is
     * stronger than per-meter. It is still one machine's answer: §7.2's
     * multi-instance caveat stands.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function withMeterLock(string $meter, callable $work)
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->pdo->prepare(
                'INSERT INTO meters (meter, updated_at) VALUES (?, ?)
                 ON CONFLICT (meter) DO UPDATE SET updated_at = excluded.updated_at'
            )->execute([$meter, $this->now()]);

            $result = $work();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');

            throw $e;
        }
    }

    // ---- §4.3 faucet ledger ----------------------------------------------

    public function faucetGranted(string $address): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM faucet_ledger WHERE address = ?');
        $stmt->execute([$address]);

        return $stmt->fetch() !== false;
    }

    /** False if this address already had its one grant (§13.2: the faucet refuses). */
    public function recordFaucet(string $address, ?string $signature = null): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO faucet_ledger (address, granted_at, signature) VALUES (?, ?, ?)'
        );
        $stmt->execute([$address, $this->now(), $signature]);

        return $stmt->rowCount() === 1;
    }

    // ---- §10.4 aggregates ------------------------------------------------

    /**
     * A fact about the article. It is incremented here and never derived from
     * `grants`, which is the second condition: deleting a reader's data must
     * not change what the site can compute.
     */
    public function countPurchase(string $article): void
    {
        $this->pdo->prepare(
            'INSERT INTO article_purchases (article, purchases) VALUES (?, 1)
             ON CONFLICT (article) DO UPDATE SET purchases = purchases + 1'
        )->execute([$article]);
    }

    public function purchases(string $article): int
    {
        $stmt = $this->pdo->prepare('SELECT purchases FROM article_purchases WHERE article = ?');
        $stmt->execute([$article]);
        $row = $stmt->fetch();

        return $row === false ? 0 : (int) $row['purchases'];
    }
}
