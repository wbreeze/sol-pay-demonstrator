<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * SPEC §10.4 qualification 2: the places `public/index.php` has to touch for
 * a late close to be erased.
 *
 * `CloseFinisherTest` covers the decision. This test covers the wiring, which
 * a unit test cannot reach, because the front controller is one file of
 * closures. Textual, like {@see SafeMethodTest} and
 * {@see FrontControllerTest}.
 *
 * Three places under the delegate design, and one today: `$binding` asks the
 * finisher before any route sees the session. The other two arrive with the
 * routes they belong to (fund design, slice 2): the key-signed close leaves a
 * note on the path that deletes nothing, and the key proof asks the finisher
 * before it binds a session. Their checks come back with them.
 */
final class LateCloseWiringTest extends TestCase
{
    public function testTheSessionLookupAsksTheFinisherFirst(): void
    {
        $body = $this->between('$binding = static function (Request $request)', "\n};\n");

        $lookup = strpos($body, 'bindingForSession(');
        $finish = strpos($body, '$finishClose(');

        self::assertIsInt($lookup);
        self::assertIsInt($finish, '$binding calls the finisher');
        self::assertGreaterThan($lookup, $finish);
        self::assertMatchesRegularExpression(
            '/\$finishClose\(\$found->meter\)\)\s*\{\s*return null;/',
            $body,
            'a session whose meter\'s close was just finished is not handed to the route',
        );
    }

    private function between(string $start, string $end): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/public/index.php');
        $from = strpos($source, $start);
        self::assertIsInt($from, "found: {$start}");
        $to = strpos($source, $end, $from);
        self::assertIsInt($to, "found the end of: {$start}");

        return substr($source, $from, $to - $from);
    }
}
