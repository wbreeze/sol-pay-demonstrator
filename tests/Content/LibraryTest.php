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
                'reading_time' => 4, 'metered' => true, 'status' => 'draft'],
            ['slug' => 'privacy', 'title' => 'Privacy', 'lede' => 'What this site holds.',
                'reading_time' => 3, 'metered' => false, 'status' => 'published'],
        ]));
        file_put_contents($this->dir.'/first-transaction.html', '<p>the body</p>');
        file_put_contents($this->dir.'/privacy.html', '<p>the list</p>');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testTheIndexListsOnlyMeteredPieces(): void
    {
        $articles = Library::load($this->dir)->articles();

        self::assertCount(1, $articles, 'the privacy page is not an article');
        self::assertSame('first-transaction', $articles[0]->slug);
        self::assertTrue($articles[0]->isDraft());
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
