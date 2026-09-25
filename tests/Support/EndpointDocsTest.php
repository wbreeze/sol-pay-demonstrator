<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The README's endpoint tables against the routing table they describe.
 *
 * `README.md` §The endpoints lists what this server answers. A list of that
 * shape goes stale the moment somebody adds a route, and it goes stale
 * quietly: nothing fails, nothing is red, and the document is simply wrong
 * until a reader trips over the gap. This repository's own convention says
 * that facts belong in a file something checks rather than in a paragraph
 * asking a reader to verify them by hand, and a route table is exactly that
 * kind of fact.
 *
 * **What this catches: a route added, removed or renamed.** It is a set
 * comparison in both directions, and the failure names the routes rather than
 * printing two arrays.
 *
 * **What it cannot catch: a row whose description went stale while the route
 * kept its name.** This compares names, not meanings — the same limit
 * {@see RouteTest} puts on {@see SafeMethodTest}, which finds the names it
 * already knows. A green run here means the README lists the right routes. It
 * does not mean the README says true things about them. Read it the way
 * `bin/content-dates` is read: it can tell you a piece moved since the date it
 * claims, and not whether the prose is still true.
 *
 * **No exemption list, on purpose.** Every route this server answers is a
 * route a reader can reach, and §1's whole argument is that you can read what
 * the site is doing. A list of routes excused from documentation would be one
 * more thing to go stale, and it would go stale in the direction of saying
 * less.
 *
 * Textual, like {@see PageTitleTest} and {@see SafeMethodTest}: the routes are
 * declared in the front controller and the documentation is a markdown file,
 * so reading both is cheaper and more complete than booting the app.
 */
final class EndpointDocsTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testTheReadmeListsEveryRouteTheServerAnswers(): void
    {
        $declared = $this->declaredRoutes();
        $documented = $this->documentedRoutes();

        self::assertNotEmpty($declared, 'no routes were read out of public/index.php');
        self::assertNotEmpty($documented, 'no routes were read out of README.md');

        $undocumented = array_diff($declared, $documented);
        self::assertSame([], array_values($undocumented), sprintf(
            "public/index.php answers these and README.md does not list them:\n  %s",
            implode("\n  ", $undocumented),
        ));

        $imaginary = array_diff($documented, $declared);
        self::assertSame([], array_values($imaginary), sprintf(
            "README.md lists these and public/index.php does not answer them:\n  %s",
            implode("\n  ", $imaginary),
        ));
    }

    /**
     * Every route the front controller declares.
     *
     * @return list<string>
     */
    private function declaredRoutes(): array
    {
        $source = (string) file_get_contents(self::ROOT.'/public/index.php');
        preg_match_all("/\\\$app->(get|post)\\(\s*'([^']+)'/", $source, $matches, PREG_SET_ORDER);

        $routes = [];
        foreach ($matches as $match) {
            $routes[] = strtoupper($match[1]).' '.$match[2];
        }

        sort($routes);

        return array_values(array_unique($routes));
    }

    /**
     * Every route the README's endpoint section names.
     *
     * Bounded to that section rather than the whole file, so a route named in
     * passing elsewhere — in §Running it, say — is not mistaken for a table
     * row. A method and a path together is the shape a row uses; a bare path
     * in prose, such as `/a/privacy` or `/favicon.ico`, is not.
     *
     * @return list<string>
     */
    private function documentedRoutes(): array
    {
        $readme = (string) file_get_contents(self::ROOT.'/README.md');

        $start = strpos($readme, '## The endpoints');
        self::assertNotFalse($start, 'README.md has no "## The endpoints" section');

        $end = strpos($readme, "\n## ", $start + 1);
        $section = $end === false ? substr($readme, $start) : substr($readme, $start, $end - $start);

        preg_match_all('/`(GET|POST) (\/[^`]*)`/', $section, $matches, PREG_SET_ORDER);

        $routes = [];
        foreach ($matches as $match) {
            $routes[] = $match[1].' '.$match[2];
        }

        sort($routes);

        return array_values(array_unique($routes));
    }
}
