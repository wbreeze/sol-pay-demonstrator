<?php

declare(strict_types=1);

namespace Newsprint\Tests\Metering;

use Newsprint\Metering\MeterMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The server's half of the prerender guard.
 *
 * `assets/read-on.js` waits for `document.prerendering` to clear, and
 * `PrerenderTest` proves it does. This is the check that does not depend on
 * that script being right: a prerendering document's own subresource requests
 * carry `Sec-Purpose: prefetch;prerender`, so a POST sent from a page nobody
 * has opened says so on the way in.
 *
 * Asserted as an *order* rather than an outcome. The header is checked before
 * the route is looked up, so a prerendering request never reaches the piece
 * lookup, the chain read or the meter — and the way to say that in a test is to
 * make all three throw. A middleware that read the header later would still
 * refuse to charge, and would have spent a round trip finding out.
 *
 * Driven through a one-route Slim app rather than by calling `process()`
 * directly, because the middleware asks Slim for the route's `slug` and that
 * question has no answer until routing has run.
 */
final class MeterMiddlewareTest extends TestCase
{
    public function testAPrerenderingRequestIsPassedThroughBeforeAnythingIsRead(): void
    {
        $response = $this->send('prefetch;prerender');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('served', (string) $response->getBody(), 'the article still serves; it is the charge that does not happen');
    }

    /**
     * The field is a token list, and `prefetch` on its own is a different
     * thing: a prefetched POST is not a POST a browser makes, so it earns no
     * exemption here. Left to the ordinary path, this request reaches the piece
     * lookup — which is what the throw proves.
     */
    public function testAPlainPrefetchHeaderIsNotTreatedAsPrerendering(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('looked up a piece');

        $this->send('prefetch');
    }

    public function testAnOrdinaryRequestIsNotShortCircuited(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('looked up a piece');

        $this->send(null);
    }

    /**
     * One route, the middleware in front of it, and every dependency throwing —
     * so reaching one of them is the failure and the handler's body is the
     * evidence that the request went through unmetered.
     */
    private function send(?string $secPurpose): ResponseInterface
    {
        $app = AppFactory::create();
        $app->post('/a/{slug}', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            self::assertNull(
                $request->getAttribute(MeterMiddleware::ATTRIBUTE),
                'no metering result, because nothing metered',
            );
            $response->getBody()->write('served');

            return $response;
        })->add(new MeterMiddleware(
            static fn (string $slug) => throw new \LogicException('looked up a piece'),
            static fn (ServerRequestInterface $r) => throw new \LogicException('read the chain'),
            static fn () => throw new \LogicException('built a meter'),
        ));
        $app->addRoutingMiddleware();

        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://example.test/a/the-logs');
        if ($secPurpose !== null) {
            $request = $request->withHeader('Sec-Purpose', $secPurpose);
        }

        return $app->handle($request);
    }
}
