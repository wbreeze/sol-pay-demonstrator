<?php

declare(strict_types=1);

namespace Newsprint\Setup;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Did this POST come from a page on this site, or from one somewhere else?
 *
 * **Everywhere else the answer is the cookie.** `Auth\Session` sets
 * `SameSite=Lax` (SPEC §5), which a browser does not send on a cross-site
 * POST, so a forged request arrives anonymous with no reader to charge and no
 * session to act on. SPEC §7.1 carries that argument and `SessionCookieTest`
 * pins the attribute.
 *
 * **The faucet is the route that guard cannot reach.** A reader asks the
 * faucet for coin before any session exists, so the request carries no cookie
 * to withhold, and a form on another site could otherwise spend the faucet
 * key's reserve (SPEC §4.3).
 *
 * First-run setup was the other such route until 2026-10-05, when it became
 * `bin/setup` and stopped being a request at all. That is the better answer
 * where it is available: a guard is what a route needs when it has to exist.
 * The class keeps its namespace because moving it would be churn.
 */
final class SameOrigin
{
    /**
     * Whether this request may act.
     *
     * Two headers, asked in order of how much they know.
     */
    public static function allows(ServerRequestInterface $request): bool
    {
        $site = trim($request->getHeaderLine('Sec-Fetch-Site'));

        if ($site !== '') {
            // The browser has already worked out the relationship and will not
            // let a page lie about it: `Sec-Fetch-*` is a forbidden header
            // name, so script cannot set it. `none` is a typed address or a
            // bookmark, which is how an operator reaches this screen the first
            // time. `same-site` is a sibling host — this deployment has none,
            // and a route that provisions should not be the place that starts
            // trusting one.
            return $site === 'same-origin' || $site === 'none';
        }

        $origin = trim($request->getHeaderLine('Origin'));

        if ($origin !== '') {
            // `Origin: null` arrives from a sandboxed frame and from some
            // redirects. It matches nothing here, which is the right answer.
            return $origin === self::origin($request);
        }

        // Neither header, so not a browser. Every browser has sent `Origin` on
        // a POST for years and a page cannot make one leave it out, so an
        // absence is `curl`, a test harness or `bin/`, and not the request
        // this refuses. Refusing it would stop those and stop nothing else.
        return true;
    }

    /**
     * This request's own origin, in the form `Origin` is written in.
     */
    private static function origin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $port = $uri->getPort();

        // `getPort()` is null when the URI carries its scheme's default port,
        // and an `Origin` omits the port in exactly that case.
        return $uri->getScheme().'://'.$uri->getHost().($port === null ? '' : ':'.$port);
    }
}
