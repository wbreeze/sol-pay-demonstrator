<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The page titles that are one phrase written twice.
 *
 * `public/index.php` hands `$shell()` a title, which becomes `<title>`, and the
 * template it renders carries its own `<h1>`. For two pages those are the same
 * words in both places: `'The meter'` five times over — four `$shell` calls and
 * one heading — and `'First run'` twice. Nothing checked that the copies agreed,
 * and on-screen words stay in the templates on purpose, so extracting them into
 * a constant is not the answer. Noticing the drift is.
 *
 * **Not every page works that way, and the difference is a convention rather
 * than an oversight.** The error and empty pages carry a terse title for a tab,
 * a bookmark and a history entry, and a sentence for the reader: *Not found* over
 * *No such page*, *Nothing built* over *Nothing is built yet*. So this asserts
 * agreement where agreement is the intent, which is the pair of pages named
 * above, and says nothing about the rest.
 *
 * Textual, like {@see SafeMethodTest}: the pairing lives in the front
 * controller's calls, and reading them is cheaper and more complete than
 * rendering every route.
 */
final class PageTitleTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** template => the phrase that has to be both its title and its heading. */
    private const ONE_PHRASE_TWICE = [
        'manage-meter' => 'The meter',
        'setup' => 'First run',
    ];

    public function testThePhraseWrittenTwiceSaysTheSameThingBothTimes(): void
    {
        foreach (self::ONE_PHRASE_TWICE as $template => $phrase) {
            self::assertSame(
                $phrase,
                $this->heading($template),
                "{$template}.php's <h1> is the reader's half of this phrase",
            );

            $titles = $this->titlesFor($template);
            self::assertNotEmpty($titles, "nothing in the front controller renders {$template}");
            self::assertSame(
                [$phrase],
                array_values(array_unique($titles)),
                "every \$shell call for {$template} has to pass '{$phrase}'",
            );
        }
    }

    /**
     * And the copies are still as many as they were.
     *
     * Equality alone passes when a route is dropped or when one call drifts to
     * a spelling this test does not look at, so the count is asserted beside it.
     */
    public function testTheRepeatedTitlesAreStillRepeated(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/public/index.php');

        self::assertSame(4, substr_count($source, "\$shell('The meter'"));
        self::assertSame(1, substr_count($source, "\$shell('First run'"));
    }

    /**
     * Every title the front controller passes alongside a render of $template.
     *
     * @return list<string>
     */
    private function titlesFor(string $template): array
    {
        $source = (string) file_get_contents(self::ROOT.'/public/index.php');
        preg_match_all(
            "/\\\$shell\\(\s*'([^']+)'\s*,\s*\\\$view->render\\(\s*'".preg_quote($template, '/')."'/",
            $source,
            $matches,
        );

        return $matches[1];
    }

    private function heading(string $template): ?string
    {
        $file = self::ROOT.'/templates/'.$template.'.php';
        self::assertFileExists($file);

        return preg_match('/<h1>([^<>]+)<\/h1>/', (string) file_get_contents($file), $m) === 1
            ? trim($m[1])
            : null;
    }
}
