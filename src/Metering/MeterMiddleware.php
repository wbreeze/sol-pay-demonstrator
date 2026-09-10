<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Chain\RequestRead;
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
     * @param callable(string): ?Piece                        $piece the article, by slug
     * @param callable(ServerRequestInterface): RequestRead   $reads this request's one chain read,
     *                                                               shared with the handler behind
     *                                                               this middleware
     * @param callable(): Meter                               $meter built lazily; an unmetered
     *                                                               request should not construct an
     *                                                               RPC client to decide it
     */
    public function __construct(
        private $piece,
        private $reads,
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

        $reads = ($this->reads)($request);
        $wallet = $reads->wallet();

        // §7.5, and the only caller of it. Asked before anything is read,
        // because a request that is not going to meter should not pay to find
        // that out — `wallet()` comes from the cookie and the store.
        if (!Decision::shouldMeter($piece, $wallet)) {
            return $handler->handle($request);
        }

        // No reader, no charge. The lede is public and the panel will offer to
        // identify — nothing here is an error.
        if ($wallet === null) {
            return $handler->handle($request);
        }

        // One round trip, five accounts: the site's three and this reader's
        // two (§12.4). The handler behind this middleware renders from the
        // same object, so nothing below is a call the page would not have made
        // anyway.
        $state = $reads->site();
        if ($state === null) {
            return $handler->handle($request);
        }

        // **`find_contract`, and it is a fork in the diagram rather than a
        // metering outcome.** A reader with no contract is on their way to
        // `set_meter`; there is nothing to meter and nothing to refuse. This
        // used to be discovered *inside* `Meter`, which read the two accounts
        // again to find it out and returned a result the panel then ignored —
        // measured 2026-09-09 at a whole round trip on the screen where a
        // reader is deciding whether to spend money.
        if ($reads->payer()?->hasContract() !== true) {
            return $handler->handle($request);
        }

        $result = ($this->meter)()->forArticle($wallet, $piece->slug, $state);

        // §7.2 puts the metering read inside the payer lock, so `Meter` has
        // looked at these two accounts more recently than this request did.
        // Which of the two answers is true afterwards depends on one thing:
        if ($result->payer !== null) {
            // Nothing was sent, and the locked read is simply the better one.
            // A limit screen should state the arithmetic its refusal was made
            // from rather than a reading taken moments earlier.
            $reads->adopt($result->payer);
        } elseif ($result->sent()) {
            // A transaction went out. §2's claim 7 says the numbers on the
            // screen came from an account, so the accounts are read again —
            // this is the one re-read that is bought on purpose.
            $reads->invalidatePayer();
        }

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
