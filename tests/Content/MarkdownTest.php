<?php

declare(strict_types=1);

namespace Newsprint\Tests\Content;

use Newsprint\Content\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * Does the build's markdown dialect render the markdown the content is written
 * in? (2026-09-14)
 *
 * The defect this exists for is not a crash. `bin/build-content` registered
 * the core extension and the front matter extension and nothing else, tables
 * are a GitHub extension you have to ask for, and so every pipe table in
 * `content/` rendered as a paragraph full of pipe characters — including the
 * one on `privacy.md`, which is the list §10.2 promises to print in full. The
 * build succeeded. The page was served. The suite was green. It was found by
 * reading the page.
 *
 * **So the assertion has to be about the HTML, never about the exit code.** A
 * build that emits the wrong thing exits 0, which is the whole shape of the
 * failure, and a test that ran the build and checked it succeeded would have
 * been green beside the bug.
 *
 * Two halves, and neither is worth anything without the other. The first puts
 * a fixture body carrying every syntax through the real converter and says
 * what each one must have become. The second reads `content/` and fails if a
 * piece uses a syntax the fixture does not — because a fixture listing the
 * syntaxes somebody thought of in September is the same trap one step along,
 * and the next missing extension (footnotes, strikethrough, a task list) will
 * fail exactly as quietly as this one did.
 */
final class MarkdownTest extends TestCase
{
    /**
     * One row per syntax: how to spot it in a source body, a sample that uses
     * it, and what the build must have produced from it.
     *
     * The detector is what makes the second test possible, so it is written
     * against the *source* and shared: one definition, used to build the
     * fixture and to interrogate the content, which is what stops the two
     * drifting into disagreement.
     *
     * @return array<string, array{detect: string, sample: string, expect: list<string>}>
     */
    private static function syntaxes(): array
    {
        return [
            'a second-level heading' => [
                'detect' => '/^## /m',
                'sample' => "## A heading\n",
                'expect' => ['<h2>A heading</h2>'],
            ],
            'a third-level heading' => [
                'detect' => '/^### /m',
                'sample' => "### A smaller heading\n",
                'expect' => ['<h3>A smaller heading</h3>'],
            ],
            // The one that was missing. `<th>` and `<td>` as well as `<table>`,
            // because an extension that produced a table of nothing would pass
            // an assertion about the outer element alone.
            'a table' => [
                'detect' => '/^\|.*\|\s*$/m',
                'sample' => "| prefix | what it names |\n| --- | --- |\n| `PID` | the metering program |\n",
                'expect' => ['<table>', '<th>prefix</th>', '<td>the metering program</td>'],
            ],
            'a blockquote' => [
                'detect' => '/^> /m',
                'sample' => "> Quoted.\n",
                'expect' => ['<blockquote>'],
            ],
            'a fenced code block' => [
                'detect' => '/^```/m',
                'sample' => "```\nmeter_and_settle\n```\n",
                'expect' => ['<pre><code>meter_and_settle'],
            ],
            'strong' => [
                'detect' => '/\*\*[^*\n]+\*\*/',
                'sample' => "This is **emphatic**.\n",
                'expect' => ['<strong>emphatic</strong>'],
            ],
            'emphasis' => [
                'detect' => '/(?<![*\w])\*[^*\n]+\*(?!\*)/',
                'sample' => "This is *stressed*.\n",
                'expect' => ['<em>stressed</em>'],
            ],
            'inline code' => [
                'detect' => '/`[^`\n]+`/',
                'sample' => "A call to `Preflight::charge`.\n",
                'expect' => ['<code>Preflight::charge</code>'],
            ],
            // A piece carries its illustrations as pairs of images, one per
            // colour scheme, and `site.css` hides the one the scheme is not —
            // so the `src` has to survive intact or the rule matches nothing.
            'an image' => [
                'detect' => '/!\[[^\]]*\]\([^)]+\)/',
                'sample' => "![The panel, closed.](/assets/img/inspector-closed-light.png)\n",
                'expect' => ['<img src="/assets/img/inspector-closed-light.png" alt="The panel, closed." />'],
            ],
        ];
    }

    /** The fixture: every sample above, in one body, separated as blocks. */
    private static function fixture(): string
    {
        return implode("\n", array_map(
            static fn (array $syntax): string => $syntax['sample'],
            self::syntaxes(),
        ));
    }

    public function testEverySyntaxInTheFixtureSurvivesTheBuild(): void
    {
        $html = (string) Markdown::converter()->convert(self::fixture());

        foreach (self::syntaxes() as $name => $syntax) {
            foreach ($syntax['expect'] as $expected) {
                self::assertStringContainsString($expected, $html, "{$name} did not render");
            }
        }
    }

    /**
     * The assertion that would have gone red before the repair, and the reason
     * it is separate: what a missing extension produces is not an error, it is
     * the source text, escaped and wrapped in a paragraph. Asserting that
     * `<table>` is present would have caught this one; asserting the pipes are
     * gone catches the general case, where an extension renders *something*
     * and leaves its own syntax lying in the prose.
     */
    public function testNoSourceSyntaxSurvivesIntoTheRenderedBody(): void
    {
        $html = (string) Markdown::converter()->convert(self::fixture());

        self::assertStringNotContainsString('| ---', $html, 'a table delimiter row reached the page');
        self::assertStringNotContainsString('| prefix |', $html, 'a table header row reached the page');
        self::assertStringNotContainsString('**', $html, 'strong markers reached the page');
        self::assertStringNotContainsString('![', $html, 'an image marker reached the page');
        self::assertStringNotContainsString('```', $html, 'a code fence reached the page');
    }

    /**
     * Every syntax the pieces actually use is one the fixture exercises.
     *
     * Read from `content/` rather than from a list, because a list is the
     * thing that goes stale. A piece that starts using a syntax nobody
     * anticipated turns this red, and the fix is one row in `syntaxes()` —
     * which will fail in `testEverySyntaxInTheFixtureSurvivesTheBuild` if the
     * dialect cannot render it, and pass if it can. That is the order the
     * table defect should have been caught in.
     *
     * **Front matter is what makes a file a piece** (§10.1), so a working note
     * living in `content/` without any is not consulted — its markdown is
     * never rendered for anybody.
     */
    public function testTheFixtureUsesEverySyntaxThePiecesDo(): void
    {
        $bodies = [];
        foreach (glob(dirname(__DIR__, 2).'/content/*.md') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            if (!str_starts_with($source, "---\n")) {
                continue;
            }

            // The front matter is the build's input, not the converter's
            // content, and a `lede` full of prose would otherwise answer for
            // syntax the body never uses.
            $bodies[basename($path)] = (string) preg_replace('/\A---\n.*?\n---\n/s', '', $source);
        }

        // A content directory this test could not read would make every
        // assertion below vacuous, and silently.
        self::assertNotEmpty($bodies, 'no pieces found to check the fixture against');

        $fixture = self::fixture();
        $covered = 0;

        foreach (self::syntaxes() as $name => $syntax) {
            $users = array_keys(array_filter(
                $bodies,
                static fn (string $body): bool => preg_match($syntax['detect'], $body) === 1,
            ));

            if ($users === []) {
                continue;
            }

            ++$covered;
            self::assertSame(
                1,
                preg_match($syntax['detect'], $fixture),
                "{$name} is used by ".implode(', ', $users).' and the fixture does not exercise it',
            );
        }

        // The same trap one level up: if the detectors stopped matching — a
        // regex broken by an edit, a content directory that moved — every loop
        // above would skip and this test would pass having checked nothing.
        self::assertGreaterThan(3, $covered, 'the detectors matched almost nothing; they are probably broken');
    }
}
