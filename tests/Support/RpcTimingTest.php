<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Chain\RpcTiming;
use Newsprint\Support\RpcTimingMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * SPEC §12.4 budgets about three RPC calls per metered page view, and until
 * 2026-09-09 nothing checked it: a browser profile timed a seven-view advance
 * at 4.07 s and the three-call decomposition was inference from the clock.
 * This is the counter that replaces the inference, and these are the two things
 * it must get right — it must count, and it must stay quiet.
 */
final class RpcTimingTest extends TestCase
{
    protected function setUp(): void
    {
        RpcTiming::reset();
        putenv('NEWSPRINT_RPC_TIMING');
    }

    protected function tearDown(): void
    {
        RpcTiming::reset();
        putenv('NEWSPRINT_RPC_TIMING');
    }

    private function middleware(): ResponseInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse();
            }
        };

        return (new RpcTimingMiddleware())->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/privacy'),
            $handler,
        );
    }

    public function testItCountsEachRoundTripInOrder(): void
    {
        RpcTiming::record('getMultipleAccounts', 912.4);
        RpcTiming::record('sendTransaction', 947.6);
        RpcTiming::record('getSignatureStatuses', 955.0);

        self::assertSame(3, RpcTiming::count());
        self::assertSame(2815, (int) round(RpcTiming::totalMs()));
        self::assertSame(
            'getMultipleAccounts=912 sendTransaction=948 getSignatureStatuses=955',
            RpcTiming::detail(),
        );
    }

    /**
     * A confirmation poll runs many times and each run is a round trip. Summing
     * them under one name would hide the thing worth seeing — that the wait was
     * nine polls rather than one slow call.
     */
    public function testRepeatedCallsAreListedRatherThanSummed(): void
    {
        RpcTiming::record('getSignatureStatuses', 500.0);
        RpcTiming::record('getSignatureStatuses', 500.0);

        self::assertSame(2, RpcTiming::count());
        self::assertSame('getSignatureStatuses=500 getSignatureStatuses=500', RpcTiming::detail());
    }

    public function testTheHeadersAppearWhenTheEnvironmentAsksForThem(): void
    {
        putenv('NEWSPRINT_RPC_TIMING=1');
        RpcTiming::record('getMultipleAccounts', 253.0);

        $response = $this->middleware();

        self::assertSame('1', $response->getHeaderLine('X-Rpc-Calls'));
        self::assertSame('253', $response->getHeaderLine('X-Rpc-Ms'));
        self::assertSame('getMultipleAccounts=253', $response->getHeaderLine('X-Rpc-Detail'));
    }

    /**
     * The half that matters more. This names internal call counts, so a
     * deployment that did not ask for it must not be told — and the switch is
     * an environment variable rather than a config value precisely because a
     * committed config value is one that eventually ships turned on.
     */
    public function testItIsSilentWhenNobodyAsked(): void
    {
        RpcTiming::record('getMultipleAccounts', 253.0);

        $response = $this->middleware();

        self::assertFalse($response->hasHeader('X-Rpc-Calls'));
        self::assertFalse($response->hasHeader('X-Rpc-Ms'));
        self::assertFalse($response->hasHeader('X-Rpc-Detail'));
    }

    /**
     * Counting happens whether or not anyone is reporting, so that turning the
     * variable on does not change what is being measured.
     */
    public function testCountingDoesNotDependOnReporting(): void
    {
        RpcTiming::record('getMultipleAccounts', 100.0);

        self::assertFalse(RpcTiming::enabled());
        self::assertSame(1, RpcTiming::count());
    }
}
