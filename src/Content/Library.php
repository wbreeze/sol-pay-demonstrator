<?php

declare(strict_types=1);

namespace Newsprint\Content;

/**
 * The built content, read from the manifest `bin/build-content` writes.
 *
 * SPEC §12.7: markdown with front matter, rendered at build time. There is no
 * CMS, no database of articles, no editor, and nothing is fetched or parsed
 * while a request is in flight — which is also what keeps §7's metering
 * decision the only interesting thing happening on an article route.
 */
final class Library
{
    /** @param array<string, Piece> $pieces by slug, in manifest order */
    private function __construct(private readonly array $pieces)
    {
    }

    public static function load(string $contentDir): self
    {
        $manifest = $contentDir.'/index.json';
        $raw = @file_get_contents($manifest);
        if ($raw === false) {
            throw new \RuntimeException('no content built; run bin/build-content');
        }

        $pieces = [];
        foreach ((array) json_decode($raw, true) as $row) {
            $piece = Piece::fromManifest($row, $contentDir);
            $pieces[$piece->slug] = $piece;
        }

        return new self($pieces);
    }

    public static function isBuilt(string $contentDir): bool
    {
        return is_file($contentDir.'/index.json');
    }

    /**
     * The metered pieces, newest first — what the index lists and the order
     * the articles link each other in.
     *
     * Until 2026-09-12 this was manifest order, which is `glob('content/*.md')`
     * order, which is alphabetical by *source filename*. Deterministic, and a
     * fact about filenames rather than about the writing: a reader saw one
     * piece above another for a reason invisible from the page, and renaming a
     * file reordered the front page silently.
     *
     * `created` and not `revised`, so that fixing a typo in an old piece does
     * not carry it back to the top; the slug breaks a tie, because four pieces
     * share a day and an order that wobbles between builds is worse than an
     * arbitrary one that does not. A piece with no date sorts last rather than
     * first — an unknown date is not news.
     *
     * @return list<Piece>
     */
    public function articles(): array
    {
        $articles = array_values(array_filter($this->pieces, static fn (Piece $p): bool => $p->metered));

        usort($articles, static function (Piece $a, Piece $b): int {
            return [$b->created ?? '', $a->slug] <=> [$a->created ?? '', $b->slug];
        });

        return $articles;
    }

    /**
     * What comes before and after a piece, chronologically.
     *
     * **Previous is the older one**, which is the way a reader reads those two
     * words and the opposite of the list's own direction: the index runs
     * newest first, so the previous piece is the one *below* this one there.
     *
     * @return array{0: ?Piece, 1: ?Piece} previous (older), next (newer)
     */
    public function neighbours(string $slug): array
    {
        $articles = $this->articles();
        $at = null;
        foreach ($articles as $i => $piece) {
            if ($piece->slug === $slug) {
                $at = $i;
                break;
            }
        }

        // Not a metered piece, or not a piece at all: the privacy page asks
        // this question too and the honest answer is that it is not in the
        // sequence.
        if ($at === null) {
            return [null, null];
        }

        return [$articles[$at + 1] ?? null, $articles[$at - 1] ?? null];
    }

    /** @return list<Piece> */
    public function all(): array
    {
        return array_values($this->pieces);
    }

    public function find(string $slug): ?Piece
    {
        return $this->pieces[$slug] ?? null;
    }
}
