<?php

declare(strict_types=1);

namespace Newsprint\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The session cookie, and nothing else in it.
 *
 * SPEC §5 makes this a claim rather than an implementation detail: the privacy
 * page argues that an authentication cookie used only for authentication is
 * the textbook "strictly necessary" case, and the site is only entitled to
 * that argument if the cookie actually behaves that way. So: one opaque id, no
 * `Expires` and no `Max-Age` — a session cookie in the literal sense, gone
 * when the browser closes — and no "remember me" anywhere.
 *
 * The header is written by hand rather than with `setcookie()` because Slim
 * hands back a PSR-7 response and `setcookie()` writes to PHP's own output
 * buffer, which is a different place and would not survive being returned.
 */
final class Session
{
    public const COOKIE = 'newsprint_session';

    public static function idFrom(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        $id = $cookies[self::COOKIE] ?? null;

        // Session ids are 64 hex characters (`Store::createSession`). Anything
        // else is a probe or a stale cookie from another site's dev server on
        // the same localhost, and there is no reason to take it to the store.
        if (!is_string($id) || preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
            return null;
        }

        return $id;
    }

    public static function issue(ResponseInterface $response, string $id, bool $secure): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', self::header(self::COOKIE.'='.$id, $secure));
    }

    /**
     * §10.4: closing the contract signs the reader out, and the cookie has to
     * go with the row. Deleting it means sending it back empty and expired —
     * a browser has no other way to be told.
     */
    public static function clear(ResponseInterface $response, bool $secure): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            self::header(self::COOKIE.'=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0', $secure),
        );
    }

    /**
     * Whether this request arrived over TLS.
     *
     * `Secure` on a cookie issued over plain http on localhost would be
     * discarded by the browser and sign-in would fail with no message, so it
     * is conditional. §6.3 makes HTTPS non-optional for the Android path, and
     * this is the line that starts setting `Secure` the moment there is a
     * certificate.
     */
    public static function isSecure(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getScheme() === 'https';
    }

    private static function header(string $pair, bool $secure): string
    {
        // SameSite=Lax, per §5. Strict would drop the cookie on the return
        // from a wallet's app switch, which is exactly §6.3's mobile path.
        $parts = [$pair, 'Path=/', 'HttpOnly', 'SameSite=Lax'];
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
