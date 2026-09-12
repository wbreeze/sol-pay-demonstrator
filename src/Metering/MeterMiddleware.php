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
 *
 * **It sits in front of `POST /a/{slug}`, and nothing else** (2026-09-11).
 * Metering changes state — it moves a reader's money — so the request that
 * does it is a POST, and `GET /a/{slug}` never meters at all: it serves the
 * body from a live grant, or it serves a shell with the lede and a form that
 * posts here. That is ordinary HTTP rather than a policy of this site's, and
 * it is the shape an integrator should copy: a GET is safe to repeat, to
 * prefetch, to prerender and to follow from a link preview, and none of those
 * can charge anybody because none of them is a POST.
 *
 * This used to be a GET, and a GET that charges needs defending from
 * everything that makes GETs for its own reasons. The defence was a list of
 * prefetch headers — `Sec-Purpose`, `Purpose`, `X-Moz` — which covered the
 * requests that announced themselves and nothing that did not. It is gone
 * because the hazard is gone. What remains is a prerendered page *running
 * the script* that sends this POST, and that is answered where it arises, in
 * `assets/read-on.js`, which waits for `document.prerendering` to clear.
 *
 * A POST is also why a cross-site page cannot spend a reader's money by
 * embedding a form: the session cookie is `SameSite=Lax`, which a browser
 * does not send on a cross-site POST, so such a request arrives anonymous and
 * this middleware lets it through without metering.
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

        $reads = ($this->reads)($request);
        $wallet = $reads->wallet();

        // §7.5. Asked before anything is read, because a request that is not
        // going to meter should not pay to find that out — `wallet()` comes
        // from the cookie and the store. The GET route asks the same question
        // for a different reason: to decide whether to send the shell.
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
}
