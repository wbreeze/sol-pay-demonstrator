<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * SPEC §10.4: the three places `public/index.php` has to touch for a late
 * close to be erased.
 *
 * `CloseFinisherTest` covers the decision. This test covers the wiring, which
 * a unit test cannot reach, because the front controller is one file of
 * closures. Textual, like {@see SafeMethodTest} and
 * {@see FrontControllerTest}.
 *
 * 1. The close route leaves a note on the path that deletes nothing.
 * 2. `$wallet` asks the finisher before any route sees the wallet.
 * 3. Identifying asks the finisher before it creates a session.
 */
final class LateCloseWiringTest extends TestCase
{
    public function testTheCloseRouteLeavesANoteWhenItDeletesNothing(): void
    {
        $body = $this->between("\$app->post('/meter/close/done'", "\n});\n");

        $check = strpos($body, '$payer->hasContract()');
        $note = strpos($body, 'recordPendingClose(');
        $pending = strpos($body, "'pending' => true");
        $erase = strpos($body, 'eraseReader(');

        self::assertIsInt($check, 'the route reads the contract account');
        self::assertIsInt($note, 'the route records a pending close');
        self::assertIsInt($pending);
        self::assertIsInt($erase);
        self::assertTrue($check < $note && $note < $pending, 'the note is written on the path that answers "pending"');
        self::assertLessThan($erase, $pending, 'and that path returns before anything is erased');
    }

    public function testTheWalletLookupAsksTheFinisherFirst(): void
    {
        $body = $this->between('$wallet = static function (Request $request)', "\n};\n");

        $lookup = strpos($body, 'walletForSession(');
        $finish = strpos($body, '$finishClose(');

        self::assertIsInt($lookup);
        self::assertIsInt($finish, '$wallet calls the finisher');
        self::assertGreaterThan($lookup, $finish);
        self::assertMatchesRegularExpression(
            '/\$finishClose\(\$address\)\)\s*\{\s*return null;/',
            $body,
            'a wallet whose close was just finished is not handed to the route',
        );
    }

    public function testIdentifyingFinishesAnEarlierCloseBeforeCreatingASession(): void
    {
        $body = $this->between("\$app->post('/signin/verify'", "\n});\n");

        $finish = strpos($body, '$finishClose(');
        $create = strpos($body, 'createSession(');

        self::assertIsInt($finish, 'the sign-in route calls the finisher');
        self::assertIsInt($create);
        self::assertLessThan($create, $finish);
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
