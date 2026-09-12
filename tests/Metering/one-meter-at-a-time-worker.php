<?php

declare(strict_types=1);

/**
 * One racing request, for `OneMeterAtATimeTest`. Not a test — PHPUnit loads
 * `*Test.php`, and this is a script `proc_open` runs in its own process.
 *
 *   php one-meter-at-a-time-worker.php \
 *       <db> <wallet> <article> <barrier-dir> <workers> <hold_us> locked|unlocked
 *
 * It prints one word: `metered` if it did the work, `granted` if it found the
 * work already done. The caller counts the words.
 *
 * The body is `Meter::forArticle`'s, with the chain taken out and nothing else
 * changed: check for a live grant, and only if there is none, do the slow
 * thing and record one. `usleep($hold)` stands where the RPC round trip
 * stands — SPEC §7.3's confirmation window is why the lock is held across a
 * network call at all, and a critical section nobody is inside long enough to
 * collide in would prove nothing.
 *
 * **The barrier is a rendezvous and not a timer**, which is the whole reason
 * this file is not flaky. Each worker announces itself and then waits for the
 * others, so all of them enter the critical section together on a fast laptop
 * and on a loaded two-core runner alike. A start time computed in advance
 * would be a bet on how long `php` takes to boot, and losing that bet quietly
 * turns the `unlocked` control below into a passing test that proves nothing.
 * This repository already owes an article to a test that failed on the
 * weather; it does not need a second one.
 *
 * `unlocked` runs the identical closure with no transaction around it. That is
 * the control: it must produce the double charge §7.2 exists to prevent.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Newsprint\Store\Database;
use Newsprint\Store\Store;

[, $db, $wallet, $article, $barrier, $workers, $holdUs, $mode] = $argv;

$store = new Store(Database::open($db), static fn (): int => time());

$work = static function () use ($store, $wallet, $article, $holdUs): string {
    if ($store->liveGrant($wallet, $article) !== null) {
        return 'granted';
    }

    usleep((int) $holdUs);

    $store->recordGrant($wallet, $article, 1_800);
    $store->countPurchase($article);

    return 'metered';
};

// Rendezvous: say we are here, then wait for everyone else. The deadline
// exists so a sibling that died takes the suite down with a failed assertion
// rather than with a hang.
touch($barrier.'/'.getmypid());
$deadline = microtime(true) + 10.0;
while (count((array) glob($barrier.'/*')) < (int) $workers && microtime(true) < $deadline) {
    usleep(200);
}

echo $mode === 'locked' ? $store->withPayerLock($wallet, $work) : $work();
