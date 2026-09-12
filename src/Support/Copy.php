<?php

declare(strict_types=1);

namespace Newsprint\Support;

/**
 * What the site says, in one place per kind (SPEC §10.x).
 *
 * Two stores, because there are two kinds of text and they want different
 * homes. **Lines** — headings, buttons, labels, a sentence of furniture — live
 * in `config/strings.php`, a keyed array. **Blocks** — the paragraphs that
 * argue for something, which on this site are most of the words — live in
 * `content/ui/*.md` and are rendered by `bin/build-content` with the same
 * commonmark configuration the articles use. A paragraph in a PHP string is a
 * paragraph nobody wants to edit; in markdown it is writing.
 *
 * The split is not cosmetic. It means a copy edit that turns one paragraph
 * into two changes no template — markdown decides the paragraph markup — while
 * a button stays a button.
 *
 * **Placeholders are values, never markup.** `{symbol}`, `{balance}`: the name
 * is `[a-z][a-z0-9_]*` and nothing else is touched, so a brace in the copy
 * survives. Values are escaped here, on the way in. Markup in the copy is the
 * site's own, so it is not escaped — which is why nothing that comes out of
 * this class is passed through `View::e` at the call site, and why `CopyTest`
 * checks that the templates do not.
 *
 * A missing key throws. The alternative — rendering the key, or an empty
 * string — puts `meter.unfunded.heading` on a reader's screen, or silently
 * removes a sentence, and the tests that would have caught it are the ones
 * that render every screen.
 */
final class Copy
{
    /** @var array<string, string> flattened once: `meter.unfunded.heading` */
    private array $lines;

    /** @var array<string, string> rendered HTML, by the same kind of key */
    private array $blocks;

    /**
     * @param array<string, mixed> $lines  as `config/strings.php` returns it
     * @param array<string, string> $blocks as `var/content/ui.json` holds it
     */
    public function __construct(array $lines, array $blocks = [])
    {
        $this->lines = self::flatten($lines);
        $this->blocks = $blocks;
    }

    public static function load(string $root): self
    {
        $lines = require $root.'/config/strings.php';

        // Built, like the articles, and gitignored with them. A missing build
        // is the same failure as a missing article body and says so the same
        // way rather than rendering a site with no words in it.
        $built = $root.'/var/content/ui.json';
        $blocks = is_file($built) ? (array) json_decode((string) file_get_contents($built), true) : [];

        return new self((array) $lines, array_map(strval(...), $blocks));
    }

    /** One line: a heading, a button, a label. Markup allowed, values escaped. */
    public function line(string $key, array $values = []): string
    {
        if (!isset($this->lines[$key])) {
            throw new \RuntimeException("no copy for '{$key}' in config/strings.php");
        }

        return self::fill($this->lines[$key], $values);
    }

    /**
     * One block of prose, as `bin/build-content` rendered it from markdown.
     *
     * The class is the template's business, not the copy's: `fine` and
     * `pending` are how this site says "smaller" and "this did not happen
     * yet", and a writer editing a paragraph should not have to know either
     * word. It is applied to the block's top-level paragraphs, which is all
     * the markup a block of prose has — the markup the templates carried
     * before this, unchanged, so the rendered page is identical and a copy
     * edit that splits a paragraph in two still gets both.
     */
    public function block(string $key, array $values = [], ?string $class = null): string
    {
        if (!isset($this->blocks[$key])) {
            throw new \RuntimeException("no copy for '{$key}' in content/ui/; run bin/build-content");
        }

        $html = self::fill($this->blocks[$key], $values);

        return $class === null ? $html : str_replace('<p>', '<p class="'.View::e($class).'">', $html);
    }

    /** @return list<string> every key this catalogue can answer for */
    public function keys(): array
    {
        return array_merge(array_keys($this->lines), array_keys($this->blocks));
    }

    /** @param array<string, string|int|float> $values */
    private static function fill(string $text, array $values): string
    {
        if ($values === []) {
            return $text;
        }

        return preg_replace_callback(
            '/\{([a-z][a-z0-9_]*)\}/',
            static function (array $m) use ($values, $text): string {
                if (!array_key_exists($m[1], $values)) {
                    throw new \RuntimeException("no value for '{$m[1]}' in: ".substr($text, 0, 60));
                }

                return View::e((string) $values[$m[1]]);
            },
            $text,
        ) ?? $text;
    }

    /**
     * `['meter' => ['unfunded' => ['heading' => …]]]` to
     * `['meter.unfunded.heading' => …]`, once, at construction.
     *
     * @param array<string, mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }
}
