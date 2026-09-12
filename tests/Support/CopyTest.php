<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Support\Copy;
use PHPUnit\Framework\TestCase;

/**
 * What keeps the words in one place after the refactor that put them there.
 *
 * A catalogue is not a mechanism, it is a convention, and conventions rot: in
 * two weeks someone types a sentence straight into a template and "the copy is
 * in one place" is quietly false, in exactly the way §9's provenance quietly
 * stopped being rendered. So the convention is checked, in the three
 * directions it can break — a key used that does not exist, a key that exists
 * and is used nowhere, and a template that grew a sentence of its own.
 */
final class CopyTest extends TestCase
{
    /**
     * Templates whose words have moved out. The list is the point: a template
     * not on it is not yet converted, and one added to it without being
     * converted fails the third test below.
     */
    private const CONVERTED = ['meter'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function copy(): Copy
    {
        return Copy::load($this->root());
    }

    /** @return list<string> every key a template asks for */
    private function used(): array
    {
        $keys = [];
        foreach (glob($this->root().'/templates/*.php') ?: [] as $file) {
            preg_match_all(
                '/\$copy->(?:line|block)\(\s*\'([a-z][a-z0-9_.]*)\'/',
                (string) file_get_contents($file),
                $m,
            );
            $keys = array_merge($keys, $m[1]);
        }

        return array_values(array_unique($keys));
    }

    public function testEveryKeyATemplateAsksForExists(): void
    {
        $copy = $this->copy();
        $known = $copy->keys();

        $used = $this->used();
        self::assertNotEmpty($used, 'no keys were found at all, which is not the same as all of them existing');

        foreach ($used as $key) {
            self::assertContains($key, $known, "{$key} is used by a template and defined nowhere");
        }
    }

    /**
     * And the other direction, which is the one that accumulates: a key whose
     * last call site was deleted is a sentence nobody can find and nobody can
     * see, and it will be edited anyway.
     */
    public function testEveryKeyThatExistsIsUsed(): void
    {
        $used = $this->used();

        foreach ($this->copy()->keys() as $key) {
            self::assertContains($key, $used, "{$key} is defined and used nowhere");
        }
    }

    /**
     * A converted template carries no sentences of its own.
     *
     * Six words is the line: `Limit`, `Authorize` and `views left` are
     * furniture a template may name, and anything longer is the site talking,
     * which belongs in the catalogue. Checked against the rendered text of the
     * file with the PHP stripped out, because a sentence in a comment is a
     * comment and a sentence in the markup is a defect.
     */
    public function testAConvertedTemplateHasNoWordsOfItsOwn(): void
    {
        foreach (self::CONVERTED as $name) {
            $source = (string) file_get_contents($this->root()."/templates/{$name}.php");

            $html = (string) preg_replace('/<\?php.*?\?>/s', ' ', $source);
            $html = (string) preg_replace('/<\?=.*?\?>/s', ' ', $html);
            $html = (string) preg_replace('/<[^>]*>/s', ' ', $html);
            $text = trim((string) preg_replace('/\s+/', ' ', $html));

            foreach (preg_split('/(?<=[.!?])\s+/', $text) ?: [] as $sentence) {
                $words = array_filter(preg_split('/\s+/', trim($sentence)) ?: []);
                self::assertLessThan(
                    6,
                    count($words),
                    "templates/{$name}.php still says: ".trim($sentence),
                );
            }
        }
    }

    /**
     * The escaping rule, in the one place it can be got wrong.
     *
     * Copy carries the site's own markup and escapes the values filled into
     * it, so passing its output through `View::e` would put `&lt;a href` on
     * the page. Nothing does it today; this is what notices when something
     * starts to.
     */
    public function testCopyIsNeverEscapedAgainAtTheCallSite(): void
    {
        foreach (glob($this->root().'/templates/*.php') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression(
                '/View::e\(\s*\$copy->/',
                (string) file_get_contents($file),
                basename($file).' escapes copy that is already escaped',
            );
        }
    }

    /** A value is escaped on the way in; the copy's own markup is not. */
    public function testValuesAreEscapedAndTheCopyIsNot(): void
    {
        $copy = new Copy(['t' => ['link' => '<a href="/meter">the meter</a> for {who}']]);

        self::assertSame(
            '<a href="/meter">the meter</a> for &lt;script&gt;',
            $copy->line('t.link', ['who' => '<script>']),
        );
    }

    /** A key with no value for one of its placeholders is a defect, not a gap. */
    public function testAPlaceholderWithNoValueThrows(): void
    {
        $copy = new Copy(['t' => ['line' => 'costs {price} {symbol}']]);

        $this->expectException(\RuntimeException::class);
        $copy->line('t.line', ['price' => '0.01']);
    }
}
