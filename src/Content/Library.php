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

    /** @return list<Piece> the metered pieces, which is what the index lists */
    public function articles(): array
    {
        return array_values(array_filter($this->pieces, static fn (Piece $p): bool => $p->metered));
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
