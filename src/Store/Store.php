<?php

declare(strict_types=1);

namespace Newsprint\Store;

use PDO;

/**
 * Every read and write the site makes about a reader, in one class, so that
 * SPEC §10.2's promise — "the actual stores, enumerated, each one traceable to
 * a line in the code" — is checkable by reading one file.
 *
 * Time is injected rather than taken from `time()` so expiry is testable
 * without sleeping. Everything here takes seconds since the epoch.
 */
final class Store
{
    public function __construct(
        private readonly PDO $pdo,
        private $clock = null,
    ) {
        $this->clock ??= static fn (): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    // ---- §5 session ------------------------------------------------------

    public function createSession(string $wallet, int $ttlSeconds): string
    {
        // 256 bits from the CSPRNG. The cookie carries this and nothing else
        // (§5), which is what entitles the privacy page to call it strictly
        // necessary.
        $id = bin2hex(random_bytes(32));
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO sessions (id, wallet, created_at, expires_at) VALUES (?, ?, ?, ?)'
        )->execute([$id, $wallet, $now, $now + $ttlSeconds]);

        return $id;
    }

    public function walletForSession(string $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT wallet FROM sessions WHERE id = ? AND expires_at > ?');
        $stmt->execute([$id, $this->now()]);
        $row = $stmt->fetch();

        return $row === false ? null : (string) $row['wallet'];
    }

    public function destroySession(string $id): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
    }

    // ---- §5 sign-in nonce ------------------------------------------------

    /**
     * @param string $input the \`SolanaSignInInput\` issued with this nonce, as JSON.
     *                      It is stored rather than recomposed because SPEC §5
     *                      step 3 compares the signed message against *the input
     *                      this server issued*, and an input rebuilt at
     *                      verification time from the current clock is a
     *                      different input.
     */
    public function issueNonce(int $ttlSeconds, string $input = '{}'): string
    {
        $nonce = bin2hex(random_bytes(16));
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO signin_nonces (nonce, issued_at, expires_at, input) VALUES (?, ?, ?, ?)'
        )->execute([$nonce, $now, $now + $ttlSeconds, $input]);

        return $nonce;
    }

    /**
     * Issue a sign-in challenge: a nonce, and the input composed around it,
     * stored together.
     *
     * The composition happens inside because the two cannot be separated
     * safely. The input names the nonce, so the nonce has to exist first; and
     * a nonce that exists without its input is a challenge a verifier cannot
     * check against anything. One INSERT, or neither.
     *
     * @param callable(string): array<string, mixed> $compose
     *
     * @return array{nonce: string, input: array<string, mixed>}
     */
    public function issueSignIn(callable $compose, int $ttlSeconds): array
    {
        $nonce = bin2hex(random_bytes(16));
        $input = $compose($nonce);
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO signin_nonces (nonce, issued_at, expires_at, input) VALUES (?, ?, ?, ?)'
        )->execute([$nonce, $now, $now + $ttlSeconds, json_encode($input, JSON_UNESCAPED_SLASHES)]);

        return ['nonce' => $nonce, 'input' => $input];
    }
    /**
     * True exactly once per nonce, and never after it expires. Both halves
     * matter: SPEC §5 step 3 says a verifier that checks neither accepts a
     * replay forever.
     */
    public function consumeNonce(string $nonce): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE signin_nonces SET used_at = ? WHERE nonce = ? AND used_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([$this->now(), $nonce, $this->now()]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Consume the nonce and hand back the input it was issued with, or null if
     * the nonce is unknown, already used, or expired.
     *
     * One call rather than two, because the two have to be indivisible: a
     * verifier that reads the input, does its checks, and only then marks the
     * nonce used has a window in which the same signed message is accepted
     * twice. Here the nonce is spent first and the input is a consequence of
     * having spent it.
     */
    public function consumeSignIn(string $nonce): ?string
    {
        if (!$this->consumeNonce($nonce)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT input FROM signin_nonces WHERE nonce = ?');
        $stmt->execute([$nonce]);
        $row = $stmt->fetch();

        return $row === false ? null : (string) $row['input'];
    }

    // ---- §7.1 view grants ------------------------------------------------

    /** @return array{expires_at: int, signature: ?string, confirmed: bool}|null */
    public function liveGrant(string $wallet, string $article): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT expires_at, signature, confirmed FROM grants
             WHERE wallet = ? AND article = ? AND expires_at > ?'
        );
        $stmt->execute([$wallet, $article, $this->now()]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'expires_at' => (int) $row['expires_at'],
            'signature' => $row['signature'] === null ? null : (string) $row['signature'],
            'confirmed' => (bool) $row['confirmed'],
        ];
    }

    /**
     * Recorded before the body is rendered (§7.3), so a render failure still
     * leaves the reader holding what they paid for. `confirmed` false is
     * §7.3's ambiguous case: sent, not confirmed inside the window, served
     * anyway and flagged in the inspector.
     */
    public function recordGrant(
        string $wallet,
        string $article,
        int $ttlSeconds,
        ?string $signature = null,
        bool $confirmed = true,
    ): void {
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO grants (wallet, article, granted_at, expires_at, signature, confirmed)
             VALUES (:w, :a, :g, :e, :s, :c)
             ON CONFLICT (wallet, article) DO UPDATE SET
                 granted_at = :g, expires_at = :e, signature = :s, confirmed = :c'
        )->execute([
            ':w' => $wallet,
            ':a' => $article,
            ':g' => $now,
            ':e' => $now + $ttlSeconds,
            ':s' => $signature,
            ':c' => $confirmed ? 1 : 0,
        ]);
    }

    /**
     * How many live grants this wallet holds.
     *
     * Not a reading history being read — a count, and the only place it is
     * used is the close confirmation, where §10.4 qualification 2 requires
     * the reader to be told *before* they click that closing costs them the
     * articles they have already paid for. Saying "one article" when it is
     * three would be a disclosure that misleads.
     */
    public function liveGrantCount(string $wallet): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS n FROM grants WHERE wallet = ? AND expires_at > ?');
        $stmt->execute([$wallet, $this->now()]);

        return (int) $stmt->fetch()['n'];
    }

    /**
     * Delete the grants, sessions and sign-in nonces that have expired, and the
     * lock rows that nothing refers to any more (§10.4 q.1).
     *
     * Expiry by itself deletes nothing. `liveGrant()` and `walletForSession()`
     * skip an expired row, and the row stays. Until 2026-09-16 nothing called
     * this method, so every lapsed grant stayed in the table until the reader
     * closed the meter: one row per wallet and article, with the time and the
     * signature. Those rows add up to a reading history, and the privacy page
     * promises a receipt that is gone in thirty minutes. The metering path now
     * calls this method inside the payer lock; see
     * {@see \Newsprint\Metering\Meter::forArticle()}. `bin/sweep` calls it on
     * a schedule, for the hours when nobody buys anything.
     *
     * A `payers` row goes once its wallet has no session and no grant left.
     * The row exists only to be locked. A wallet address kept after the
     * reader's session and grants are gone is a store the privacy page does
     * not list.
     *
     * An expired nonce names no reader and can never be consumed, so deleting
     * it is housekeeping rather than privacy. A used nonce that has not yet
     * expired stays: `consumeNonce()` needs the row to refuse a replay.
     *
     * A pending-close note goes when it expires, or once its wallet has no
     * session and no grant left, since the note then protects nothing.
     *
     * @return array{grants: int, sessions: int, payers: int, nonces: int, closes: int}
     */
    public function sweepExpired(): array
    {
        $now = $this->now();

        $grants = $this->pdo->prepare('DELETE FROM grants WHERE expires_at <= ?');
        $grants->execute([$now]);

        $sessions = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at <= ?');
        $sessions->execute([$now]);

        // After the two deletes above, so a wallet whose last session or
        // grant just went loses its lock row in the same sweep.
        $payers = $this->pdo->prepare(
            'DELETE FROM payers
             WHERE wallet NOT IN (SELECT wallet FROM sessions)
               AND wallet NOT IN (SELECT wallet FROM grants)'
        );
        $payers->execute();

        $nonces = $this->pdo->prepare('DELETE FROM signin_nonces WHERE expires_at <= ?');
        $nonces->execute([$now]);

        $closes = $this->pdo->prepare(
            'DELETE FROM pending_closes
             WHERE expires_at <= ?
                OR (wallet NOT IN (SELECT wallet FROM sessions)
                    AND wallet NOT IN (SELECT wallet FROM grants))'
        );
        $closes->execute([$now]);

        return [
            'grants' => $grants->rowCount(),
            'sessions' => $sessions->rowCount(),
            'payers' => $payers->rowCount(),
            'nonces' => $nonces->rowCount(),
            'closes' => $closes->rowCount(),
        ];
    }

    /**
     * How long the oldest expired grant or session has waited for a sweep, in
     * seconds, or null when nothing is waiting.
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
             )'
        );
        $stmt->execute([':now' => $now]);
        $oldest = $stmt->fetch()['oldest'] ?? null;

        return $oldest === null ? null : $now - (int) $oldest;
    }

    // ---- §10.4 erasure ---------------------------------------------------

    /**
     * Closing the contract purges the site's record of the reader: session,
     * grants, the lock row, and the note of a close still waiting to be
     * confirmed. The faucet ledger deliberately survives, for the published
     * reason in §10.4 qualification 3.
     *
     * Returns what went, because §10.4 says this is testable from outside and
     * the inspector shows the DELETE happening rather than asserting it.
     *
     * @return array{sessions: int, grants: int}
     */
    public function eraseReader(string $wallet): array
    {
        $sessions = $this->pdo->prepare('DELETE FROM sessions WHERE wallet = ?');
        $sessions->execute([$wallet]);
        $grants = $this->pdo->prepare('DELETE FROM grants WHERE wallet = ?');
        $grants->execute([$wallet]);
        $this->pdo->prepare('DELETE FROM payers WHERE wallet = ?')->execute([$wallet]);
        $this->pdo->prepare('DELETE FROM pending_closes WHERE wallet = ?')->execute([$wallet]);

        return ['sessions' => $sessions->rowCount(), 'grants' => $grants->rowCount()];
    }

    // ---- §10.4 a close the chain had not confirmed yet -------------------

    /**
     * Remember that this wallet sent a close the server stopped waiting for.
     *
     * `POST /meter/close/done` waits a bounded time and then reads the
     * contract account. When the account is still there, nothing is deleted,
     * and the close may still land a few seconds later. Without this note, the
     * request that comes after cannot tell a wallet that has just closed its
     * meter from one that never opened a meter, and the erasure never runs.
     * {@see \Newsprint\Metering\CloseFinisher} reads the note.
     */
    public function recordPendingClose(string $wallet, string $signature, int $ttlSeconds): void
    {
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO pending_closes (wallet, signature, sent_at, expires_at)
             VALUES (:w, :s, :n, :e)
             ON CONFLICT (wallet) DO UPDATE SET signature = :s, sent_at = :n, expires_at = :e'
        )->execute([':w' => $wallet, ':s' => $signature, ':n' => $now, ':e' => $now + $ttlSeconds]);
    }

    /** @return array{signature: string, sent_at: int}|null */
    public function pendingClose(string $wallet): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT signature, sent_at FROM pending_closes WHERE wallet = ? AND expires_at > ?'
        );
        $stmt->execute([$wallet, $this->now()]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'signature' => (string) $row['signature'],
            'sent_at' => (int) $row['sent_at'],
        ];
    }

    /** The close did not land and no longer can. */
    public function dropPendingClose(string $wallet): void
    {
        $this->pdo->prepare('DELETE FROM pending_closes WHERE wallet = ?')->execute([$wallet]);
    }

    // ---- §7.2 one meter at a time per payer ------------------------------

    /**
     * Run `$work` with this payer serialized against every other request for
     * the same wallet.
     *
     * `BEGIN IMMEDIATE` takes SQLite's write lock at the start rather than on
     * first write, which is the point: the read, the preflight, the meter and
     * the grant have to be inside one critical section, and a deferred
     * transaction would upgrade halfway through and lose the race it exists
     * to prevent. The `payers` row is touched so the lock and the state it
     * guards are the same object (§12.5).
     *
     * Note the lock is database-wide (see {@see Database}), so this is
     * stronger than per-payer. It is still one machine's answer: §7.2's
     * multi-instance caveat stands.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function withPayerLock(string $wallet, callable $work)
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->pdo->prepare(
                'INSERT INTO payers (wallet, updated_at) VALUES (?, ?)
                 ON CONFLICT (wallet) DO UPDATE SET updated_at = excluded.updated_at'
            )->execute([$wallet, $this->now()]);

            $result = $work();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');

            throw $e;
        }
    }

    // ---- §4.3 faucet ledger ----------------------------------------------

    public function faucetGranted(string $wallet): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM faucet_ledger WHERE wallet = ?');
        $stmt->execute([$wallet]);

        return $stmt->fetch() !== false;
    }

    /** False if this wallet already had its one grant (§13.2: the faucet refuses). */
    public function recordFaucet(string $wallet, ?string $signature = null): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO faucet_ledger (wallet, granted_at, signature) VALUES (?, ?, ?)'
        );
        $stmt->execute([$wallet, $this->now(), $signature]);

        return $stmt->rowCount() === 1;
    }

    // ---- §10.4 q.5 aggregates --------------------------------------------

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
