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
 * Three parts, and none is worth much without the others. The first puts a
 * fixture body carrying every supported syntax through the real converter and
 * says what each one must have become. The second reads `content/` and fails
 * if a piece uses a syntax the fixture does not. The third names the syntaxes
 * this dialect cannot render and fails if a piece uses one of those.
 *
 * **The third part was missing until 2026-09-14 and the gap was the same shape
 * as the original defect.** Checking the fixture against the content only asks
 * about syntaxes already on the list, so a syntax on neither list was
 * invisible — which is precisely what a missing extension is. A piece gained a
 * markdown link and a bullet list that afternoon, and this file stayed green;
 * both happen to render, and nothing here knew it.
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
            'a link' => [
                'detect' => '/(?<!!)\[[^\]]+\]\([^)]+\)/',
                'sample' => "The [sol-pay](https://github.com/wbreeze/sol-pay) client.\n",
                'expect' => ['<a href="https://github.com/wbreeze/sol-pay">sol-pay</a>'],
            ],
            'a bullet list' => [
                'detect' => '/^[-*] (?!\[[ xX]\])/m',
                'sample' => "- one\n- two\n",
                'expect' => ['<ul>', '<li>one</li>'],
            ],
            'an ordered list' => [
                'detect' => '/^\d+\. /m',
                'sample' => "1. first\n2. second\n",
                'expect' => ['<ol>', '<li>first</li>'],
            ],
            // Not a syntax anybody defined, which is why nothing caught it
            // for a fortnight: two hyphens rendered as two hyphens, and the
            // same keystroke is right in `wasm-client/SPEC.md`, which goes
            // through a converter that folds it into a dash. The detector
            // excludes a longer run, so a table's delimiter row and a front
            // matter fence are not this.
            'an em dash from two hyphens' => [
                'detect' => '/(?<!-)--(?!-)/',
                'sample' => "Two hyphens -- typed like this -- mean a dash.\n",
                'expect' => ['Two hyphens — typed like this — mean a dash.'],
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

    /**
     * Syntaxes this dialect does **not** render, and what a piece using one
     * would get instead.
     *
     * Added 2026-09-14, because the first version of this test could not have
     * caught the defect that prompted it. It asked, for each syntax in the
     * list, whether the fixture covered what the content used — so a syntax
     * that was in *neither* was invisible, which is exactly what a missing
     * extension looks like. The proof arrived the same day: a piece gained a
     * markdown link and a bullet list, and this file stayed green. Both happen
     * to render, and nothing here knew that either.
     *
     * So the list has a second half: syntaxes with a detector and no sample,
     * whose appearance in `content/` is a failure rather than a gap. Adding
     * the extension that renders one — and moving its row up into
     * {@see syntaxes()} — is the fix when that day comes.
     *
     * @return array<string, array{detect: string, gets: string}>
     */
    private static function unsupported(): array
    {
        return [
            'strikethrough' => [
                'detect' => '/~~[^~\n]+~~/',
                'gets' => 'the tildes, printed',
            ],
            'a footnote' => [
                'detect' => '/\[\^[^\]]+\]/',
                'gets' => 'the reference and the definition, both as literal text',
            ],
            'a task list' => [
                'detect' => '/^[-*] \[[ xX]\] /m',
                'gets' => 'a list item opening with a literal bracket',
            ],
            'an autolink' => [
                'detect' => '/<https?:\/\//',
                'gets' => 'nothing — it is raw HTML, and raw HTML is stripped',
            ],
            // Not an oversight and not fixable by an extension: `html_input:
            // 'strip'` is how §10.3 is enforced rather than merely stated. The
            // cost is that a body reaching for `<figure>` loses it without a
            // word, which is worth failing loudly over.
            'raw HTML' => [
                'detect' => '/^<[a-zA-Z]/m',
                'gets' => 'nothing; it is stripped on purpose, so §10.3 cannot be broken by a body',
            ],
        ];
    }

    /**
     * The em dash conversion stops at the edge of code, and it stops short of
     * guessing.
     *
     * Separate from the fixture because these are the cases where getting it
     * wrong would be worse than not doing it at all. A code span is where a
     * reader finds something to type, so `--no-dev` folded into `—no-dev` would
     * be an instruction that fails for whoever trusts it. Code is literal
     * before inline parsing reaches it, so this holds by construction — and it
     * is asserted rather than trusted, because "by construction" is the kind of
     * claim that stops being true when somebody changes the dialect.
     *
     * A run of three or more is left as typed. At the start of a line it is a
     * thematic break, a setext underline or a front matter fence, all of them
     * blocks; anywhere else, printing what was written beats inventing a dash
     * the writer may not have meant.
     */
    public function testTheEmDashStopsAtCodeAndAtLongerRuns(): void
    {
        $converter = Markdown::converter();

        $span = (string) $converter->convert("Install with `composer install --no-dev` first.\n");
        self::assertStringContainsString('<code>composer install --no-dev</code>', $span);
        self::assertStringNotContainsString('—', $span, 'a flag a reader is meant to type');

        $fenced = (string) $converter->convert("```\nbin/sweep --verbose\n```\n");
        self::assertStringContainsString('bin/sweep --verbose', $fenced);
        self::assertStringNotContainsString('—', $fenced);

        $longer = (string) $converter->convert("Three --- hyphens mid-sentence.\n");
        self::assertStringContainsString('Three --- hyphens', $longer);

        // The front matter fence and a table's delimiter row are blocks, parsed
        // before any inline parser runs. Asserted because both are three-hyphen
        // runs sitting in every piece in `content/`.
        $table = (string) $converter->convert("| a | b |\n| --- | --- |\n| 1 | 2 |\n");
        self::assertStringContainsString('<table>', $table);
        self::assertStringNotContainsString('—', $table);
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

        // And the half the first version of this test was missing: a syntax
        // in neither list is invisible, so the syntaxes the dialect cannot
        // render are named too, and using one is a failure rather than a gap.
        foreach (self::unsupported() as $name => $syntax) {
            $users = array_keys(array_filter(
                $bodies,
                static fn (string $body): bool => preg_match($syntax['detect'], $body) === 1,
            ));

            self::assertSame(
                [],
                $users,
                "{$name} is used by ".implode(', ', $users).", and this build renders {$syntax['gets']}",
            );
        }
    }
}
