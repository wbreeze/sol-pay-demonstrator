<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Store\Store;

/**
 * SPEC §10.4: finish an erasure whose close landed after the server stopped
 * waiting.
 *
 * Closing waits for the chain and then reads the meter account. When the
 * account is still there, the server deletes nothing and leaves a note
 * ({@see Store::recordPendingClose()}). Until 2026-09-17 there was no note, so
 * a close that landed at second twenty-five was never followed by an erasure.
 * The reader reloaded into "nothing to close", and the session and grants
 * stayed.
 *
 * Every request that knows its meter asks this class first. With no note, the
 * answer costs one indexed read and no chain call. With a note, the class asks
 * the chain whether the meter still exists:
 *
 * - **Gone.** The close landed. Erase now, exactly as the close would have.
 * - **Still there, and the close can no longer land.** A transaction is dead
 *   once its blockhash has expired, so after `$settleSeconds` the note is
 *   dropped and nothing is erased.
 * - **Still there, and the close may yet land.** Keep the note and wait.
 * - **The chain did not answer.** Keep the note. Nothing is erased on a maybe.
 */
final class CloseFinisher
{
    public function __construct(
        private readonly Store $store,
        private readonly int $settleSeconds,
    ) {
    }

    /**
     * @param callable(): ?bool $meterExists true or false from a fresh read of
     *                                       the meter account, or null when the
     *                                       chain could not be read. Called only
     *                                       when a note exists.
     *
     * @return array{sessions: int, grants: int}|null what the erasure deleted,
     *                                                or null when nothing was erased
     */
    public function finish(string $meter, callable $meterExists): ?array
    {
        $note = $this->store->pendingClose($meter);
        if ($note === null) {
            return null;
        }

        $exists = $meterExists();
        if ($exists === null) {
            return null;
        }

        if ($exists === false) {
            return $this->store->eraseMeter($meter);
        }

        if ($this->store->now() >= $note['sent_at'] + $this->settleSeconds) {
            $this->store->dropPendingClose($meter);
        }

        return null;
    }
}
