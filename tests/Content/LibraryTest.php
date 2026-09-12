<?php

declare(strict_types=1);

namespace Newsprint\Tests\Content;

use Newsprint\Content\Library;
use PHPUnit\Framework\TestCase;

final class LibraryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pp-content-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/index.json', json_encode([
            ['slug' => 'first-transaction', 'title' => 'The transaction', 'lede' => 'A validator accepted it.',
                'reading_time' => 4, 'metered' => true, 'status' => 'draft', 'created' => '2026-09-05'],
            ['slug' => 'privacy', 'title' => 'Privacy', 'lede' => 'What this site holds.',
                'reading_time' => 3, 'metered' => false, 'status' => 'published', 'created' => '2026-09-04', 'revised' => '2026-09-07'],
        ]));
        file_put_contents($this->dir.'/first-transaction.html', '<p>the body</p>');
        file_put_contents($this->dir.'/privacy.html', '<p>the list</p>');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * A library of metered pieces in the given order, plus the privacy page.
     *
     * @param list<array{slug: string, created: ?string}> $rows
     */
    private function library(array $rows): Library
    {
        $manifest = [];
        foreach ($rows as $row) {
            $manifest[] = [
                'slug' => $row['slug'], 'title' => $row['slug'], 'lede' => 'A lede.',
                'reading_time' => 4, 'metered' => true, 'status' => 'draft',
                'created' => $row['created'],
            ];
            file_put_contents($this->dir.'/'.$row['slug'].'.html', '<p>the body</p>');
        }

        $manifest[] = ['slug' => 'privacy', 'title' => 'Privacy', 'lede' => '',
            'reading_time' => 3, 'metered' => false, 'status' => 'published', 'created' => '2026-09-04'];

        file_put_contents($this->dir.'/index.json', (string) json_encode($manifest));

        return Library::load($this->dir);
    }

    public function testTheIndexListsOnlyMeteredPieces(): void
    {
        $articles = Library::load($this->dir)->articles();

        self::assertCount(1, $articles, 'the privacy page is not an article');
        self::assertSame('first-transaction', $articles[0]->slug);
        self::assertTrue($articles[0]->isDraft());
    }

    /**
     * Newest first, and not the order the files happen to be named in.
     *
     * The fixture is deliberately written in the order `glob` would produce —
     * which is what this used to return — so that a regression to manifest
     * order fails here rather than passing because the two agreed.
     */
    public function testTheIndexRunsNewestFirstAndBreaksTiesOnTheSlug(): void
    {
        $library = $this->library([
            ['slug' => 'a-first-alphabetically', 'created' => '2026-09-05'],
            ['slug' => 'b-same-day-as-c', 'created' => '2026-09-07'],
            ['slug' => 'c-same-day-as-b', 'created' => '2026-09-07'],
            ['slug' => 'd-newest', 'created' => '2026-09-11'],
            ['slug' => 'e-undated', 'created' => null],
        ]);

        self::assertSame(
            ['d-newest', 'b-same-day-as-c', 'c-same-day-as-b', 'a-first-alphabetically', 'e-undated'],
            array_map(static fn ($p): string => $p->slug, $library->articles()),
        );
    }

    /**
     * Previous is the older piece, which is the opposite of the list's order.
     *
     * Both ends are asserted, because an off-by-one here does not throw: it
     * links the newest piece to the oldest and reads as a feature.
     */
    public function testTheNeighboursOfAPieceRunAgainstTheList(): void
    {
        $library = $this->library([
            ['slug' => 'oldest', 'created' => '2026-09-05'],
            ['slug' => 'middle', 'created' => '2026-09-07'],
            ['slug' => 'newest', 'created' => '2026-09-11'],
        ]);

        [$previous, $next] = $library->neighbours('middle');
        self::assertSame('oldest', $previous?->slug, 'previous is the older piece');
        self::assertSame('newest', $next?->slug);

        [$previous, $next] = $library->neighbours('newest');
        self::assertSame('middle', $previous?->slug);
        self::assertNull($next, 'nothing is newer than the newest');

        [$previous, $next] = $library->neighbours('oldest');
        self::assertNull($previous, 'and nothing older than the oldest');
        self::assertSame('middle', $next?->slug);

        // The privacy page asks this too, being rendered by the same route
        // furniture, and it is not in the sequence.
        self::assertSame([null, null], $library->neighbours('privacy'));
        self::assertSame([null, null], $library->neighbours('never-written'));
    }

    public function testABodyIsReadOnlyWhenItIsAskedFor(): void
    {
        $piece = Library::load($this->dir)->find('first-transaction');

        self::assertNotNull($piece);
        // §6.1: the lede travels with the piece, the body does not — a caller
        // has to ask, which is what makes withholding it structural.
        self::assertSame('A validator accepted it.', $piece->lede);
        self::assertSame('<p>the body</p>', $piece->body());
    }

    public function testAMissingBodySaysWhatToRun(): void
    {
        unlink($this->dir.'/first-transaction.html');
        $piece = Library::load($this->dir)->find('first-transaction');

        $this->expectException(\RuntimeException::class);
        $piece->body();
    }

    public function testAnUnbuiltDirectoryIsRecognised(): void
    {
        self::assertTrue(Library::isBuilt($this->dir));
        self::assertFalse(Library::isBuilt($this->dir.'/nope'));
    }
}
