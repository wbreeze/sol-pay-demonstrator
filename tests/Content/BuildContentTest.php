<?php

declare(strict_types=1);

namespace Newsprint\Tests\Content;

use PHPUnit\Framework\TestCase;

/**
 * What `bin/build-content` refuses.
 *
 * The build's front matter rules are the only thing standing between a typo and
 * a published page, and none of them had a test: they are refusals, so nothing
 * that succeeds exercises them, and a refusal nothing exercises can stop
 * refusing without anyone noticing.
 *
 * `status` is the one worth the file. Every reader of the value asks
 * `=== 'draft'` — `Piece::isDraft()` and `Content\DateAudit` both — so a
 * misspelled draft does not fall back to the safe answer. It publishes, with
 * its dates unaudited, and the first person to find out is a reader.
 *
 * Run as a process against a fixture directory, the way `SweepScriptTest` runs
 * the sweep, because the thing under test is the script.
 */
final class BuildContentTest extends TestCase
{
    private string $source = '';
    private string $target = '';

    protected function setUp(): void
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'newsprint-build-');
        unlink($base);
        $this->source = $base.'-src';
        $this->target = $base.'-out';
        mkdir($this->source, 0o755, true);
        mkdir($this->target, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->source, $this->target] as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testAPieceBuildsWhenItsFrontMatterIsWhole(): void
    {
        $this->piece('good.md', 'published');

        [$code, , $err] = $this->build();

        self::assertSame(0, $code, $err);
        self::assertFileExists($this->target.'/a-good-piece.html');
    }

    public function testADraftBuildsToo(): void
    {
        $this->piece('draft.md', 'draft');

        [$code, , $err] = $this->build();

        self::assertSame(0, $code, $err);
    }

    /**
     * The repair. `draftt` published before this, silently, because the build
     * read the value and checked nothing and every consumer compares it with
     * `'draft'`.
     */
    public function testAMisspelledStatusIsRefusedRatherThanPublished(): void
    {
        $this->piece('typo.md', 'draftt');

        [$code, , $err] = $this->build();

        self::assertSame(1, $code, 'a status nobody recognises must not build');
        self::assertStringContainsString("'status' is 'draftt'", $err);
        self::assertStringContainsString("must be 'draft' or 'published'", $err);
        self::assertFileDoesNotExist($this->target.'/a-good-piece.html');
    }

    private function piece(string $file, string $status): void
    {
        file_put_contents($this->source.'/'.$file, <<<MD
            ---
            title: A good piece
            slug: a-good-piece
            created: 2026-09-01
            metered: false
            status: {$status}
            ---

            A body.
            MD);
    }

    /** @return array{int, string, string} */
    private function build(): array
    {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/build-content', '--quiet'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'NEWSPRINT_CONTENT_SOURCE' => $this->source,
                'NEWSPRINT_CONTENT_TARGET' => $this->target,
            ],
        );
        self::assertIsResource($proc, 'could not start bin/build-content');

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
