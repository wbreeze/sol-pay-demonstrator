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
 * 1. The key-signed close leaves a note on the path that deletes nothing.
 * 2. `$binding` asks the finisher before any route sees the session.
 * 3. The key proof asks the finisher before it binds a session.
 */
final class LateCloseWiringTest extends TestCase
{
    public function testTheCloseRouteLeavesANoteWhenItDeletesNothing(): void
    {
        $body = $this->between("\$app->post('/meter/close',", "\n});\n");

        $check = strpos($body, '$meterExists($held->meter) !== false');
        $note = strpos($body, 'recordPendingClose(');
        $pending = strpos($body, "'pending' => true");
        $erase = strpos($body, 'eraseMeter(');

        self::assertIsInt($check, 'the route reads the meter account after the send');
        self::assertIsInt($note, 'the route records a pending close');
        self::assertIsInt($pending);
        self::assertIsInt($erase);
        self::assertTrue($check < $note && $note < $pending, 'the note is written on the path that answers "pending"');
        self::assertLessThan($erase, $pending, 'and that path returns before anything is erased');
    }

    public function testTheKeyProofFinishesAnEarlierCloseBeforeBindingASession(): void
    {
        $body = $this->between("\$app->post('/key/prove'", "\n});\n");

        $finish = strpos($body, '$finishClose(');
        $create = strpos($body, 'createSession(');

        self::assertIsInt($finish, 'the proof route calls the finisher');
        self::assertIsInt($create);
        self::assertLessThan($create, $finish);
    }

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
