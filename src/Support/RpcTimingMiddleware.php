<?php

declare(strict_types=1);

namespace Newsprint\Support;

use Newsprint\Chain\RpcTiming;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Reports this request's RPC round trips in response headers, when asked.
 *
 * `NEWSPRINT_RPC_TIMING=1 bin/run-dev`, and then every response carries:
 *
 *     X-Rpc-Calls: 3
 *     X-Rpc-Ms: 2814
 *     X-Rpc-Detail: getMultipleAccounts=912 sendTransaction=947 getSignatureStatuses=955
 *
 * **Outermost, so it sees redirects.** Added last in `public/index.php`, which
 * in Slim means it wraps everything else: the 303 out of `/meter/advance` is
 * the response carrying the metering transaction's calls, and a middleware
 * added anywhere further in would miss it. The seven-view advance's cost is
 * split across two requests — the POST and the article it redirects to — and
 * the whole point is to see both halves separately.
 *
 * **Headers rather than a log**, because the artefact that already holds the
 * timings is a HAR or a Gecko profile. A count in a separate file is a count
 * somebody has to line up by hand against a timestamp.
 *
 * **Nothing is stored and nothing is per-reader.** The numbers describe one
 * request's traffic to an endpoint; they name no wallet and survive nothing.
 * §10.4's enumeration is unaffected.
 */
final class RpcTimingMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        if (!RpcTiming::enabled()) {
            return $response;
        }

        return $response
            ->withHeader('X-Rpc-Calls', (string) RpcTiming::count())
            ->withHeader('X-Rpc-Ms', (string) (int) round(RpcTiming::totalMs()))
            ->withHeader('X-Rpc-Detail', RpcTiming::detail());
    }
}
