<?php

declare(strict_types=1);

namespace Newsprint\Content;

/**
 * Whether a piece's dates are still true, given what the repository says.
 *
 * The dates in front matter are claims a writer makes, kept that way on
 * purpose: only a person can tell a revision from a touch-up, and `git log`
 * cannot. On 2026-09-12 a single commit removed a repeated heading from every
 * piece in `content/`, which changed nothing a reader can see — derived dates
 * would have re-dated the whole site that morning.
 *
 * What git *is* good for is noticing that a claim has gone stale, which is the
 * only thing asked of it here. The comparison lives in this class rather than
 * in `bin/content-dates` because the tree reaches the test container without
 * `.git`: the plumbing that reads the repository is in the script, and the
 * rule it applies is here, where it can be tested in both directions.
 */
final class DateAudit
{
    /**
     * @param array{created: ?string, revised: ?string, status: string} $piece
     * @param ?string $added   the date of the commit that first added the file
     * @param ?string $touched the date of the last commit that changed it,
     *                         ignoring those a commit declared reader-invisible
     *
     * @return list<string> what is wrong, in the order it is worth reading
     */
    public static function complaints(string $name, array $piece, ?string $added, ?string $touched): array
    {
        $created = $piece['created'];
        $revised = $piece['revised'];
        $out = [];

        if ($created === null) {
            return ["{$name}: no 'created' date"];
        }

        // A piece can be written long before it is committed; it cannot be
        // written after. This one holds for a draft too, because it is a fact
        // about a file rather than a claim to a reader.
        if ($added !== null && $created > $added) {
            $out[] = "{$name}: created {$created}, but the file was already committed on {$added}";
        }

        // The staleness check is for published pieces only. A draft is still
        // being written — the dates are not on any page yet, and nagging about
        // them would train someone to bump a date to quiet a check, which is
        // the opposite of what the date is for.
        if ($piece['status'] === 'draft') {
            return $out;
        }

        $claimed = $revised ?? $created;
        if ($touched !== null && $touched > $claimed) {
            $out[] = $revised === null
                ? "{$name}: says it was written {$created} and never revised, but it changed on {$touched}"
                : "{$name}: says it was revised {$revised}, but it changed on {$touched}";
        }

        return $out;
    }
}
