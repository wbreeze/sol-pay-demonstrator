<?php

declare(strict_types=1);

namespace Newsprint\Content;

/**
 * One piece, as the build produced it (SPEC §10.1).
 *
 * The two halves are separate on purpose and §6.1 says why: the **lede** is
 * public and gives the server something honest to render to a visitor with no
 * contract, so `set_meter` appears beside real content instead of as a wall.
 * The **body** is what a reader pays for, and it is never sent to a browser
 * that has not paid — not hidden with CSS, not delivered and revealed. A
 * `Piece` therefore carries its lede as text and its body only as a path,
 * which makes the withholding structural rather than remembered.
 */
final class Piece
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $lede,
        public readonly int $readingTime,
        public readonly bool $metered,
        public readonly string $status,
        /**
         * When the piece was written, and when it last changed in a way a
         * reader would notice — `YYYY-MM-DD`, or null for a manifest built
         * before either existed. Both are the writer's claim rather than
         * anything counted: see `bin/build-content` on `reading_time` for why
         * that distinction is kept, and `bin/content-dates` for what stops the
         * claim going stale.
         */
        public readonly ?string $created,
        public readonly ?string $revised,
        private readonly string $bodyPath,
    ) {
    }

    /** @param array<string, mixed> $row from the build manifest */
    public static function fromManifest(array $row, string $contentDir): self
    {
        return new self(
            (string) $row['slug'],
            (string) $row['title'],
            (string) $row['lede'],
            (int) $row['reading_time'],
            (bool) $row['metered'],
            (string) ($row['status'] ?? 'published'),
            isset($row['created']) ? (string) $row['created'] : null,
            isset($row['revised']) ? (string) $row['revised'] : null,
            $contentDir.'/'.$row['slug'].'.html',
        );
    }

    /**
     * The rendered body. Every caller of this is a decision to hand a reader
     * something they had to pay for, so there are few of them and they are
     * easy to find.
     */
    public function body(): string
    {
        $html = @file_get_contents($this->bodyPath);
        if ($html === false) {
            throw new \RuntimeException("no built body for {$this->slug}; run bin/build-content");
        }

        return $html;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * The date to show, and nothing for a draft.
     *
     * A draft is not published, so it has no publication date to give — and a
     * creation date shown where a reader expects one would answer a question
     * nobody asked with a date about the workshop. The draft badge says what
     * there is to say. The dates stay in the front matter meanwhile, so
     * publishing a piece is one word rather than an archaeology exercise.
     */
    public function shownDate(): ?string
    {
        return $this->isDraft() ? null : $this->created;
    }

    /** The revision, when there is one and it is not the day it was written. */
    public function shownRevision(): ?string
    {
        if ($this->isDraft() || $this->revised === null) {
            return null;
        }

        return $this->revised === $this->created ? null : $this->revised;
    }
}
