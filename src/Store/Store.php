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

    public function issueNonce(int $ttlSeconds): string
    {
        $nonce = bin2hex(random_bytes(16));
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO signin_nonces (nonce, issued_at, expires_at) VALUES (?, ?, ?)'
        )->execute([$nonce, $now, $now + $ttlSeconds]);

        return $nonce;
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

    /** Expiry does the work; this is the sweep that makes it visible (§10.4 q.1). */
    public function sweepExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM grants WHERE expires_at <= ?');
        $stmt->execute([$this->now()]);

        return $stmt->rowCount();
    }

    // ---- §10.4 erasure ---------------------------------------------------

    /**
     * Closing the contract purges the site's record of the reader: session,
     * grants, and the lock row. The faucet ledger deliberately survives, for
     * the published reason in §10.4 qualification 3.
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

        return ['sessions' => $sessions->rowCount(), 'grants' => $grants->rowCount()];
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
