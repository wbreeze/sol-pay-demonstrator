<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Content\Piece;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * SPEC §12.1: "the §7 metering decision is a PSR-15 middleware over
 * `SolPay\Core`."
 *
 * Middleware rather than a few lines in the route handler, and the reason is
 * §7.1's: metering wired straight into a route handler is the defect most
 * likely to be shipped, because a route handler runs on every request and a
 * charge should not. Putting the decision in front of the handler makes the
 * boundary a thing you can point at — and makes the handler's job "render the
 * body if this attribute says I may", which is short enough to read.
 *
 * It sets one request attribute and renders nothing. Every branch — served,
 * blocked, refused, unreachable — is a value the handler turns into a screen,
 * because §8 says every branch that leaves the happy path early is a screen
 * and not an error page.
 */
final class MeterMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'newsprint.metering';

    /**
     * @param callable(string): ?Piece                            $piece     the article, by slug
     * @param callable(ServerRequestInterface): ?string           $wallet    the session's wallet address
     * @param callable(): ?\Newsprint\Chain\SiteState             $siteState this site, decoded
     * @param callable(): Meter                                   $meter     built lazily; an unmetered
     *                                                                       request should not construct
     *                                                                       an RPC client to decide it
     */
    public function __construct(
        private $piece,
        private $wallet,
        private $siteState,
        private $meter,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $slug = RouteContext::fromRequest($request)->getRoute()?->getArgument('slug');
        $piece = is_string($slug) ? ($this->piece)($slug) : null;

        if (!$piece instanceof Piece) {
            return $handler->handle($request);
        }

        // §7.1 lists a browser prefetch among the things that are not page
        // views. The grant covers the *second* one and every one after it,
        // but the first would charge a reader for an article they never
        // opened — so a request that announces itself as speculative is not
        // metered at all. Chrome and Firefox each say so their own way.
        if (self::isSpeculative($request)) {
            return $handler->handle($request);
        }

        $wallet = ($this->wallet)($request);

        // §7.5, and the only caller of it.
        if (!Decision::shouldMeter($piece, $wallet)) {
            return $handler->handle($request);
        }

        // No reader, no charge. The lede is public and the panel will offer to
        // identify — nothing here is an error.
        if ($wallet === null) {
            return $handler->handle($request);
        }

        $state = ($this->siteState)();
        if ($state === null) {
            return $handler->handle($request);
        }

        $result = ($this->meter)()->forArticle($wallet, $piece->slug, $state);

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $result));
    }

    /**
     * A request the browser made on its own guess, not because a reader asked.
     *
     * Trusting a header the client sets is exactly right here: the header only
     * ever costs the site a charge it chose not to make, and a client that
     * lies about it is asking to be given the article for free — which is a
     * problem this demo does not have and a real site bounds with the grant it
     * would then write. Nothing is served differently; the body still waits on
     * a real request.
     */
    private static function isSpeculative(ServerRequestInterface $request): bool
    {
        foreach (['Sec-Purpose' => 'prefetch', 'Purpose' => 'prefetch', 'X-Moz' => 'prefetch'] as $header => $needle) {
            if (str_contains(strtolower($request->getHeaderLine($header)), $needle)) {
                return true;
            }
        }

        return false;
    }
}
