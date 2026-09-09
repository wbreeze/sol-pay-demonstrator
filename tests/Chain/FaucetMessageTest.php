<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\Faucet;
use Newsprint\Support\Config;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SolPay\Core\Cause;
use SolPay\Core\Ids;

/**
 * The faucet's refusal has to reach a reader.
 *
 * On 2026-09-09 a drained faucet reported `Transaction simulation failed:
 * Error processing Instruction 0: custom program error: 0x1` — and even that
 * never appeared on screen, because `tx.js`'s `post()` throws
 * `payload.message || 'the server refused that'` on any non-2xx and this route
 * was the one place in the front controller that answered an error without a
 * `message`. An afternoon went into a diagnosis the server had already made.
 *
 * These are structural checks on that contract rather than on the chain: they
 * read the route and the client helper as text, because what failed was the
 * agreement between two files, and no test that exercises either one alone
 * would have noticed.
 */
final class FaucetMessageTest extends TestCase
{
    private function frontController(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/public/index.php');
    }

    /**
     * The agreement itself: `post()` reads `message`, so every error status
     * this helper can meet must carry one.
     */
    public function testPostReadsMessageAndTheFaucetSendsOne(): void
    {
        $tx = (string) file_get_contents(dirname(__DIR__, 2).'/public/assets/tx.js');

        self::assertStringContainsString(
            'payload.message',
            $tx,
            'post() is the contract these routes are written against',
        );

        $faucet = $this->route('/faucet');
        self::assertStringContainsString(
            "'message' =>",
            $faucet,
            'the faucet answers 409 on refusal, and post() shows message or nothing',
        );
    }

    /** The shape `Faucet::grant()` promises, so the route can rely on it. */
    public function testGrantAlwaysCarriesBothFields(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Chain/Faucet.php');

        // Every return from grant() — three of them — names both fields.
        preg_match_all('/return \[[^;]*?\];/s', $source, $returns);
        $arrays = array_filter($returns[0], static fn (string $r): bool => str_contains($r, "'granted' =>"));

        self::assertCount(3, $arrays, 'grant() has three exits: spent, failed, granted');
        foreach ($arrays as $return) {
            self::assertStringContainsString("'reason' =>", $return, 'what the chain said');
            self::assertStringContainsString("'message' =>", $return, 'what a reader is told');
        }
    }

    /** @param non-empty-string $raisedBy */
    private function told(string $raisedBy, int $code): string
    {
        $program = Config::load(dirname(__DIR__, 2))->program();
        $tell = new ReflectionMethod(Faucet::class, 'tell');
        $tell->setAccessible(true);

        return (string) $tell->invoke(null, Cause::of($program, $raisedBy, $code));
    }

    /**
     * The regression this file exists for, in its second form.
     *
     * The first fix gave the faucet a `message`; it then said `code 1 from
     * 11111111111111111111111111111111 — a program neither this site nor
     * sol-pay names`, because `Causes::describe()` answers `CauseKind::Unknown`
     * with a deliberate non-guess and `?? $fallback` therefore never fired.
     * Precise, true, and no help. The faucet composed the transaction, so it
     * knows what the describer cannot: instruction 0 is a System transfer out
     * of its own account.
     */
    public function testADrainedFaucetSaysSoRatherThanNamingTheSystemProgram(): void
    {
        $said = $this->told(Ids::SYSTEM_PROGRAM_ID, 1);

        self::assertStringNotContainsString('11111111111111111111111111111111', $said);
        self::assertStringNotContainsString('neither this site nor sol-pay names', $said);
        self::assertStringContainsString('its own account is empty', $said);
    }

    /**
     * The other half, and the one that keeps this honest: the sharp sentence
     * is claimed for exactly one code from exactly one program. Anything else
     * unnamed gets the general answer, because the faucet knows what
     * instruction 0 is and nothing about the rest.
     */
    public function testItDoesNotClaimAnEmptyAccountForEverySystemFault(): void
    {
        self::assertStringNotContainsString('its own account is empty', $this->told(Ids::SYSTEM_PROGRAM_ID, 0));
        self::assertStringNotContainsString(
            'its own account is empty',
            $this->told('Vote111111111111111111111111111111111111111', 1),
        );
    }

    /** A cause the library *can* name keeps the library's sentence. */
    public function testNamedCausesArePassedThrough(): void
    {
        self::assertStringContainsString('InsufficientFunds', $this->told(Ids::TOKEN_PROGRAM_ID, 1));
    }

    private function route(string $path): string
    {
        $source = $this->frontController();
        $start = strpos($source, "\$app->post('{$path}'");
        self::assertIsInt($start, "route {$path} not found");
        $end = strpos($source, "\n});", $start);
        self::assertIsInt($end);

        return substr($source, $start, $end - $start);
    }
}
