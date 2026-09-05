<?php

declare(strict_types=1);

namespace Newsprint\Store;

use PDO;

/**
 * The one SQLite file, and its schema (SPEC §12.5).
 *
 * Four stores, all small and all short-lived: the session (§5), view grants
 * (§7.1), the per-payer serialization the metering path takes (§7.2), and the
 * faucet's one-grant-per-wallet record (§4.3). SPEC §10.4 enumerates them and
 * the privacy page repeats the enumeration, so a table added here is a claim
 * on that page that has to be updated with it.
 *
 * **On the word "row" in §12.5.** SQLite's write lock is database-wide, not
 * per row: `BEGIN IMMEDIATE` serializes *every* writer, not just the ones
 * touching one payer. It therefore delivers §7.2 and then some. WAL keeps
 * readers out of that queue. The stronger guarantee costs nothing at this
 * scale and the spec's real caveat is untouched — one file on one machine is
 * still not a lock that spans instances.
 */
final class Database
{
    public static function open(string $path): PDO
    {
        $fresh = !is_file($path);
        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Every statement below is written out; emulation would hide a
            // type surprise until it reached the chain.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Readers do not block the writer, which matters because the metering
        // path holds a write transaction across an RPC round trip (§7.3).
        $pdo->exec('PRAGMA journal_mode = WAL');
        // A second request for the same payer waits here rather than failing;
        // this is the visible half of §7.2's queue. Longer than §7.3's
        // confirmation window on purpose.
        $pdo->exec('PRAGMA busy_timeout = 30000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        if ($fresh && $path !== ':memory:') {
            @chmod($path, 0o600);
        }
        self::migrate($pdo);

        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            -- §5. The viewer-to-wallet map, which is the integrator's one
            -- obligation. A publisher with accounts would put the address on
            -- the account row instead and change nothing else.
            CREATE TABLE IF NOT EXISTS sessions (
                id         TEXT PRIMARY KEY,
                wallet     TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS sessions_wallet ON sessions (wallet);

            -- §5 step 3. A verifier that skips the expiry accepts a replay
            -- forever, so the nonce carries one and is marked used.
            CREATE TABLE IF NOT EXISTS signin_nonces (
                nonce      TEXT PRIMARY KEY,
                issued_at  INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                used_at    INTEGER
            );

            -- §7.1. A receipt, not a profile: it answers "has this wallet
            -- already paid for this article", is never joined across
            -- articles, never leaves the server, and expires.
            CREATE TABLE IF NOT EXISTS grants (
                wallet     TEXT NOT NULL,
                article    TEXT NOT NULL,
                granted_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                signature  TEXT,
                confirmed  INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (wallet, article)
            );
            CREATE INDEX IF NOT EXISTS grants_expiry ON grants (expires_at);

            -- §7.2. The row the metering path locks. It holds no reading
            -- history — its only purpose is to exist so a transaction can be
            -- taken against it — and it is purged on close with the rest.
            CREATE TABLE IF NOT EXISTS payers (
                wallet     TEXT PRIMARY KEY,
                updated_at INTEGER NOT NULL
            );

            -- §4.3 and §10.4 qualification 3. This one survives a close, and
            -- the reason is published rather than assumed: the faucet's mint
            -- is an on-chain transaction naming that account forever, so the
            -- row duplicates a public fact and could be replaced by a chain
            -- query. Without it, close-and-refaucet is a loop.
            CREATE TABLE IF NOT EXISTS faucet_ledger (
                wallet     TEXT PRIMARY KEY,
                granted_at INTEGER NOT NULL,
                signature  TEXT
            );

            -- §10.4 qualification 5. Counts, not joins: a fact about the
            -- article. The count must not need the row, which is why it is
            -- incremented here and never derived from `grants`.
            CREATE TABLE IF NOT EXISTS article_purchases (
                article   TEXT PRIMARY KEY,
                purchases INTEGER NOT NULL DEFAULT 0
            );
        SQL);
    }
}
