<?php

declare(strict_types=1);

namespace Newsprint\Tests\Auth;

use Newsprint\Auth\Session;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * **A POST that another site starts carries no session**, so it charges
 * nobody.
 *
 * The browser enforces that, and it enforces it only because the cookie says
 * `SameSite=Lax`. The attribute is one word in `Session::header()`, and
 * nothing else in the suite would notice it change. Two changes are
 * plausible and both are wrong:
 *
 * - `SameSite=None`, or no attribute at all. The cookie then travels with a
 *   form another site posts to an article, and that form charges the reader.
 * - `SameSite=Strict`. The cookie stays home on the return from a wallet's
 *   app, which is the mobile path of SPEC §6.3, and the reader arrives as a
 *   stranger in the middle of identifying.
 *
 * The rest of what is asserted here is §5's claim about the cookie: one
 * opaque id, readable by no script, and gone when the browser closes.
 */
final class SessionCookieTest extends TestCase
{
    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testTheIssuedCookieIsLaxAndHttpOnly(): void
    {
        $attributes = self::attributes(Session::issue((new ResponseFactory())->createResponse(), self::ID, false));

        self::assertSame(Session::COOKIE.'='.self::ID, $attributes[0]);
        self::assertContains('SameSite=Lax', $attributes);
        self::assertContains('HttpOnly', $attributes);
        self::assertContains('Path=/', $attributes);
        self::assertSame(1, count(preg_grep('/^SameSite=/i', $attributes) ?: []), 'one SameSite attribute, not two');
    }

    public function testTheIssuedCookieIsASessionCookie(): void
    {
        $attributes = self::attributes(Session::issue((new ResponseFactory())->createResponse(), self::ID, false));

        self::assertSame([], preg_grep('/^(Expires|Max-Age)=/i', $attributes) ?: [], 'no lifetime: gone when the browser closes');
    }

    public function testSecureIsSetOnlyOverTls(): void
    {
        $plain = self::attributes(Session::issue((new ResponseFactory())->createResponse(), self::ID, false));
        $tls = self::attributes(Session::issue((new ResponseFactory())->createResponse(), self::ID, true));

        self::assertNotContains('Secure', $plain, 'a Secure cookie over plain http is discarded');
        self::assertContains('Secure', $tls);
        self::assertContains('SameSite=Lax', $tls);
    }

    /**
     * Clearing sends the cookie back with the same attributes. A browser
     * matches a deletion to the cookie it holds by name, path and domain, and
     * a clearing header that drifted from the issuing one is a deletion that
     * quietly misses.
     */
    public function testTheClearingCookieCarriesTheSameAttributes(): void
    {
        $attributes = self::attributes(Session::clear((new ResponseFactory())->createResponse(), false));

        self::assertSame(Session::COOKIE.'=', $attributes[0]);
        self::assertContains('Max-Age=0', $attributes);
        self::assertContains('SameSite=Lax', $attributes);
        self::assertContains('HttpOnly', $attributes);
        self::assertContains('Path=/', $attributes);
    }

    /** @return list<string> the one Set-Cookie header, split at its semicolons */
    private static function attributes(ResponseInterface $response): array
    {
        $headers = $response->getHeader('Set-Cookie');
        self::assertCount(1, $headers, 'one cookie');

        return array_map('trim', explode(';', $headers[0]));
    }
}
