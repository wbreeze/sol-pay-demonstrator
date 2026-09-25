<?php

declare(strict_types=1);

namespace Newsprint\Tests\Setup;

use Newsprint\Setup\SameOrigin;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The one POST route with no session to speak for it.
 *
 * `SessionCookieTest` proves `SameSite=Lax` is set, which is what stops a
 * cross-site POST reaching a reader's money (SPEC §7.1). `POST /setup` carries
 * no session at all, so that proof says nothing about it, and this is the
 * substitute.
 *
 * The route test is the one that matters. A guard nothing calls is
 * `sweepExpired()` again: a passing unit test proves the function works and
 * proves nothing about whether the site runs it.
 */
final class SameOriginTest extends TestCase
{
    public function testAPageOnThisSiteIsAllowed(): void
    {
        self::assertTrue(SameOrigin::allows(
            $this->post('http://localhost:8000/setup', ['Origin' => 'http://localhost:8000']),
        ));
    }

    public function testAPageSomewhereElseIsRefused(): void
    {
        self::assertFalse(SameOrigin::allows(
            $this->post('http://localhost:8000/setup', ['Origin' => 'https://example.test']),
        ));
    }

    /** The same host on another port is another origin, and says so. */
    public function testAnotherPortIsAnotherOrigin(): void
    {
        self::assertFalse(SameOrigin::allows(
            $this->post('http://localhost:8000/setup', ['Origin' => 'http://localhost:9000']),
        ));
    }

    /** A default port is absent from the URI and from the `Origin` alike. */
    public function testADefaultPortMatches(): void
    {
        self::assertTrue(SameOrigin::allows(
            $this->post('https://example.test/setup', ['Origin' => 'https://example.test']),
        ));
    }

    /** A sandboxed frame sends this, and it is nobody's origin. */
    public function testALiteralNullOriginIsRefused(): void
    {
        self::assertFalse(SameOrigin::allows(
            $this->post('http://localhost:8000/setup', ['Origin' => 'null']),
        ));
    }

    /**
     * `Sec-Fetch-Site` is asked first and answers on its own.
     *
     * The `Origin` here would be allowed, so a pass would mean the second
     * header had been consulted after the first had already refused.
     */
    public function testFetchMetadataDecidesBeforeOrigin(): void
    {
        self::assertFalse(SameOrigin::allows($this->post('http://localhost:8000/setup', [
            'Sec-Fetch-Site' => 'cross-site',
            'Origin' => 'http://localhost:8000',
        ])));
    }

    /** A typed address or a bookmark, which is how the screen is first opened. */
    public function testFetchMetadataNoneIsAllowed(): void
    {
        self::assertTrue(SameOrigin::allows(
            $this->post('http://localhost:8000/setup', ['Sec-Fetch-Site' => 'none']),
        ));
    }

    public function testFetchMetadataSameOriginIsAllowed(): void
    {
        self::assertTrue(SameOrigin::allows(
            $this->post('http://localhost:8000/setup', ['Sec-Fetch-Site' => 'same-origin']),
        ));
    }

    /**
     * Neither header means no browser, and that is not the request this
     * refuses — see the class docblock. `bin/` and every command-line check
     * arrive this way.
     */
    public function testNoHeadersAtAllIsAllowed(): void
    {
        self::assertTrue(SameOrigin::allows($this->post('http://localhost:8000/setup', [])));
    }

    /**
     * The claim the route has to make: a forged POST is refused, and the
     * provisioner is never built — the container this runs in cannot reach
     * devnet, so a request that got as far as an RPC call could not answer 403.
     */
    public function testTheSetupRouteRefusesACrossSitePost(): void
    {
        $response = $this->app()->handle($this->post('http://localhost:8000/setup', [
            'Sec-Fetch-Site' => 'cross-site',
            'Origin' => 'https://example.test',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'did not come from a page on this site',
            (string) $response->getBody(),
            'the screen has to say why, in its own vocabulary',
        );
    }

    /** @param array<string, string> $headers */
    private function post(string $uri, array $headers): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function app(): App
    {
        $app = require dirname(__DIR__, 2).'/public/index.php';
        self::assertInstanceOf(App::class, $app, 'public/index.php must hand back the app under the CLI');

        return $app;
    }
}
