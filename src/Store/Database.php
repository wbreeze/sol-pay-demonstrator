<?php

declare(strict_types=1);

namespace Newsprint\Store;

use PDO;
use PDOException;

/**
 * The one SQLite file, and its schema (SPEC §12.5).
 *
 Seven stores, all small and all but one short-lived: the session (§5.3),
 * pending setups (§6.3), proof nonces (§5.2), view grants (§7.1), the
 * per-meter serialization the metering path takes (§7.2), pending closes
 * (§10.4), and the faucet's one-grant-per-address record (§4.3). SPEC §10.4
 * enumerates them and the privacy page repeats the enumeration, so a table
 * added here is a claim on that page that has to be updated with it.
 *
 * **On the word "row" in §12.5.** SQLite's write lock is database-wide, not
 * per row: `BEGIN IMMEDIATE` serializes *every* writer, not just the ones
 * touching one meter. It therefore delivers §7.2 and then some. WAL keeps
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
        // same meter waits here rather than failing; this is the visible half
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

    /**
     * The schema this code expects, in SQLite's own `user_version`.
     *
     * Version 2 is the fund design (2026-10-01). Everything the delegate
     * design kept was keyed by wallet, and the fund design keys by meter, so
     * nothing a reader left behind carries over: sessions, grants and lock
     * rows from before are dropped, which ends every session once. Two tables
     * are kept, because neither is reader data that the redesign changes. The
     * faucet ledger duplicates a public fact and must survive (§10.4
     * qualification 4), and the purchase counts are facts about articles.
     *
     * Version 3 (slice 2) adds `sessions.close_message`: the `close_meter`
     * message the server compiled, kept until the page returns it signed
     * (SPEC §5.4). A version-2 file gains the column and loses nothing.
     */
    public const VERSION = 3;

    public static function migrate(PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version >= self::VERSION) {
            return;
        }

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Asked again under the write lock. Four requests opening a fresh
            // file together all read version 0 above, and the three that
            // queued here must find the first one's work rather than drop it.
            if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= self::VERSION) {
                $pdo->exec('COMMIT');

                return;
            }

            $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            if ($current < 2) {
                self::fromDelegateDesign($pdo);
                self::create($pdo);
            } else {
                self::addColumn($pdo, 'sessions', 'close_message', 'TEXT');
            }
            $pdo->exec('PRAGMA user_version = '.self::VERSION);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');

            throw $e;
        }
    }

    /**
     * Drop the delegate design's tables, carrying the faucet ledger across
     * under its new key. A fresh database has none of them, and this does
     * nothing to it.
     */
    private static function fromDelegateDesign(PDO $pdo): void
    {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

        $ledger = [];
        if (in_array('faucet_ledger', $tables, true) && self::hasColumn($pdo, 'faucet_ledger', 'wallet')) {
            $ledger = $pdo->query('SELECT wallet, granted_at, signature FROM faucet_ledger')->fetchAll();
            $pdo->exec('DROP TABLE faucet_ledger');
        }

        foreach (['sessions', 'signin_nonces', 'grants', 'payers', 'pending_closes'] as $old) {
            if (in_array($old, $tables, true)) {
                $pdo->exec("DROP TABLE {$old}");
            }
        }

        if ($ledger === []) {
            return;
        }

        self::create($pdo);
        $insert = $pdo->prepare('INSERT OR IGNORE INTO faucet_ledger (address, granted_at, signature) VALUES (?, ?, ?)');
        foreach ($ledger as $row) {
            $insert->execute([$row['wallet'], $row['granted_at'], $row['signature']]);
        }
    }

    private static function create(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            -- §5.3. The viewer-to-meter map, which is the integrator's one
            -- obligation. The fund's address saves a read; the key is what
            -- every charge checks the meter still names. A publisher with
            -- accounts would put these on the account row instead.
            CREATE TABLE IF NOT EXISTS sessions (
                id         TEXT PRIMARY KEY,
                meter      TEXT NOT NULL,
                fund       TEXT NOT NULL,
                key        TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                -- §5.4. The close the server compiled for this session's
                -- key to sign, base64, until it comes back signed.
                close_message TEXT
            );
            CREATE INDEX IF NOT EXISTS sessions_meter ON sessions (meter);

            -- §6.3. A setup the reader has started and not continued. It
            -- holds what the panel asked, and the wallet's address once the
            -- wallet has asked for the transaction. Ten minutes, or until
            -- *continue*. Written by slice 3's routes; the sweep covers it now.
            CREATE TABLE IF NOT EXISTS pending_setups (
                id         TEXT PRIMARY KEY,
                session    TEXT NOT NULL,
                key        TEXT NOT NULL,
                answers    TEXT NOT NULL DEFAULT '{}',
                wallet     TEXT,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            );

            -- §5.2. A proof's nonce, forgotten at its first presentation
            -- whether the proof then passes or not, or after five minutes.
            CREATE TABLE IF NOT EXISTS nonces (
                nonce      TEXT PRIMARY KEY,
                issued_at  INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            );

            -- §7.1. A receipt, not a profile: it answers "has this meter
            -- already paid for this article", is never joined across
            -- articles, never leaves the server, and expires.
            CREATE TABLE IF NOT EXISTS grants (
                meter      TEXT NOT NULL,
                article    TEXT NOT NULL,
                granted_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                signature  TEXT,
                charge     TEXT NOT NULL DEFAULT 'pending',
                PRIMARY KEY (meter, article)
            );
            CREATE INDEX IF NOT EXISTS grants_expiry ON grants (expires_at);

            -- §7.2. The row the metering path locks. It holds no reading
            -- history; its only purpose is to exist so a transaction can be
            -- taken against it, and it is purged on close with the rest.
            CREATE TABLE IF NOT EXISTS meters (
                meter      TEXT PRIMARY KEY,
                updated_at INTEGER NOT NULL
            );

            -- §10.4 qualification 2. A close the server sent that the chain
            -- had not confirmed when it stopped waiting. The meter and the
            -- close's signature, both public in that transaction. It goes
            -- with the erasure, or when the close is found not to have
            -- landed, or after one session's life at the most.
            CREATE TABLE IF NOT EXISTS pending_closes (
                meter      TEXT PRIMARY KEY,
                signature  TEXT NOT NULL,
                sent_at    INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            );

            -- §4.3 and §10.4 qualification 4. This one survives a close, and
            -- the reason is published rather than assumed: the faucet's
            -- transfer is an on-chain transaction naming that address
            -- forever, so the row duplicates a public fact. Without it,
            -- close-and-refaucet is a loop.
            CREATE TABLE IF NOT EXISTS faucet_ledger (
                address    TEXT PRIMARY KEY,
                granted_at INTEGER NOT NULL,
                signature  TEXT
            );

            -- §10.4's aggregates. Counts, not joins: a fact about the
            -- article. The count must not need the row, which is why it is
            -- incremented here and never derived from `grants`.
            CREATE TABLE IF NOT EXISTS article_purchases (
                article   TEXT PRIMARY KEY,
                purchases INTEGER NOT NULL DEFAULT 0
            );
        SQL);
    }

    private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!self::hasColumn($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM pragma_table_info(?) WHERE name = ?');
        $stmt->execute([$table, $column]);

        return $stmt->fetch() !== false;
    }
}
