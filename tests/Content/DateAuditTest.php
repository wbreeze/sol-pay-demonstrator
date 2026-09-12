<?php

declare(strict_types=1);

namespace Newsprint\Tests\Content;

use Newsprint\Content\DateAudit;
use PHPUnit\Framework\TestCase;

/**
 * The rule `bin/content-dates` applies, without the repository it reads.
 *
 * The script's own work is plumbing — two `git log` invocations — and it
 * cannot run where this suite runs, since the tree reaches the container
 * without `.git`. What is worth testing is the judgement, and it is tested in
 * both directions: every case below has a counterpart that must *not*
 * complain, because a check that cannot fail is the thing this project keeps
 * finding in place of a check.
 */
final class DateAuditTest extends TestCase
{
    /** @param array{created?: ?string, revised?: ?string, status?: string} $piece */
    private function complaints(array $piece, ?string $added, ?string $touched): array
    {
        return DateAudit::complaints('a-piece.md', [
            // `array_key_exists`, not `??`: one case below passes a null
            // creation date on purpose, and a default would swallow it.
            'created' => array_key_exists('created', $piece) ? $piece['created'] : '2026-09-07',
            'revised' => $piece['revised'] ?? null,
            'status' => $piece['status'] ?? 'published',
        ], $added, $touched);
    }

    public function testAPublishedPieceThatChangedAfterTheDateItClaimsIsCaught(): void
    {
        self::assertSame([], $this->complaints([], '2026-09-07', '2026-09-07'));

        $stale = $this->complaints([], '2026-09-07', '2026-09-12');
        self::assertCount(1, $stale);
        self::assertStringContainsString('never revised', $stale[0]);
        self::assertStringContainsString('2026-09-12', $stale[0]);

        // And with a revision claimed: the same question, asked of the later
        // of the two dates.
        self::assertSame([], $this->complaints(['revised' => '2026-09-12'], '2026-09-07', '2026-09-12'));

        $behind = $this->complaints(['revised' => '2026-09-10'], '2026-09-07', '2026-09-12');
        self::assertCount(1, $behind);
        self::assertStringContainsString('revised 2026-09-10', $behind[0]);
    }

    /**
     * A draft is exempt from the staleness check and from nothing else.
     *
     * The dates of a draft are on no page — `Piece::shownDate()` withholds
     * them — so a complaint about one would be a check nobody can act on
     * except by bumping a date to quiet it.
     */
    public function testADraftIsNotNaggedAboutRevisions(): void
    {
        self::assertSame([], $this->complaints(['status' => 'draft'], '2026-09-07', '2026-09-12'));

        // But a creation date after the file was committed is wrong whatever
        // the status, because it is a claim about the file and not about a
        // reader.
        $impossible = $this->complaints(['status' => 'draft', 'created' => '2026-09-09'], '2026-09-07', '2026-09-12');
        self::assertCount(1, $impossible);
        self::assertStringContainsString('already committed', $impossible[0]);
    }

    public function testAMissingDateIsTheFirstThingSaidAndTheOnlyThing(): void
    {
        $missing = $this->complaints(['created' => null], '2026-09-07', '2026-09-12');
        self::assertSame(["a-piece.md: no 'created' date"], $missing);
    }

    /**
     * No history to compare against is not a complaint.
     *
     * A file added in the working tree and not yet committed has no dates in
     * git at all, and the answer to "is this claim stale" is then that nothing
     * is known — which is different from the claim being wrong.
     */
    public function testAFileGitHasNeverSeenPassesQuietly(): void
    {
        self::assertSame([], $this->complaints([], null, null));
    }
}
