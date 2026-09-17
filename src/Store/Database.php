<?php

declare(strict_types=1);

namespace Newsprint\Store;

use PDO;
use PDOException;

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

        // **First, before anything that can contend.** A second request for the
        // same payer waits here rather than failing; this is the visible half
        // of §7.2's queue, and it is longer than §7.3's confirmation window on
        // purpose.
        //
        // The order is the point. SQLite's default busy timeout is zero, so
        // every statement issued before this line fails outright the moment
        // another connection holds the write lock. This stood the other way
        // round until 2026-09-12, when `Metering\OneMeterAtATimeTest` caught
        // it: four requests opening the database together, and one dying with
        // `database is locked` at
        // `PRAGMA journal_mode` before it ever reached the queue it was
        // supposed to join. Not a test artefact — the metering path holds its
        // transaction across an RPC round trip, so the window in which a
        // second request opens the database against a held write lock is
        // exactly §7.3's confirmation window wide, and the reader sees a 500
        // instead of their article.
        $pdo->exec('PRAGMA busy_timeout = 30000');

        // Readers do not block the writer, which matters because the metering
        // path holds a write transaction across an RPC round trip (§7.3).
        //
        // **The busy timeout above does not cover this statement.** That is the
        // correction to what the paragraph above claimed until 2026-09-13:
        // putting the timeout first was necessary, and it is not sufficient.
        // Converting a database to WAL needs an exclusive lock, and SQLite does
        // not consult the busy handler to get it — it answers `database is
        // locked` at once. Measured on 3.45.1: one connection with
        // `busy_timeout = 30000` against another holding a write transaction
        // failed the conversion in 1 ms, while an ordinary INSERT on that same
        // connection waited 2.5 s and succeeded. So the wait is written out
        // here instead, to the same 30 seconds.
        //
        // It can only ever loop on a database that is not yet WAL, which is a
        // database nobody has finished opening: once the mode is set this is a
        // header read that cannot contend. First run is therefore the whole
        // window, and `Metering\OneMeterAtATimeTest` sits in it on every run,
        // because its `setUp` hands four processes a path with no file at the
        // end of it. That is CI run 94055745969 — six of eight matrix legs red,
        // every one of them on the line below.
        $deadline = microtime(true) + 30.0;
        while (true) {
            try {
                $pdo->exec('PRAGMA journal_mode = WAL');
                break;
            } catch (PDOException $e) {
                // 5 is SQLITE_BUSY. Anything else is not a queue to join.
                if (($e->errorInfo[1] ?? null) !== 5 || microtime(true) > $deadline) {
                    throw $e;
                }

                usleep(1_000);
            }
        }

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
                used_at    INTEGER,
                -- The \`SolanaSignInInput\` issued with this nonce. §5 step 3
                -- checks the signed message against what was issued, so what
                -- was issued has to outlive the request that issued it.
                input      TEXT NOT NULL DEFAULT '{}'
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
                charge     TEXT NOT NULL DEFAULT 'confirmed',
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

            -- §10.4, 2026-09-17. A close the reader sent that the chain had
            -- not confirmed when the server stopped waiting. It holds the
            -- wallet and the close's signature, both already public on chain
            -- in that transaction. It lets a later request finish the
            -- erasure once the contract is gone, and it goes with the
            -- erasure, or when the close is found not to have landed, or
            -- after one session's life at the most.
            CREATE TABLE IF NOT EXISTS pending_closes (
                wallet     TEXT PRIMARY KEY,
                signature  TEXT NOT NULL,
                sent_at    INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
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

        self::addColumn($pdo, 'signin_nonces', 'input', "TEXT NOT NULL DEFAULT '{}'");
        // §7.3, 2026-09-17: the article is served before its charge confirms,
        // so a grant carries what became of the charge. `confirmed` stays and
        // is written alongside it — true exactly when this says `confirmed`.
        self::addColumn($pdo, 'grants', 'charge', "TEXT NOT NULL DEFAULT 'confirmed'");
    }

    /**
     * \`CREATE TABLE IF NOT EXISTS\` does nothing to a table that already
     * exists, so a column added after someone has run the site is invisible to
     * it. This is the whole migration story the demo needs: no versions table,
     * no down migrations, just the columns that arrived late.
     */
    private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM pragma_table_info(?) WHERE name = ?');
        $stmt->execute([$table, $column]);
        if ($stmt->fetch() !== false) {
            return;
        }

        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
