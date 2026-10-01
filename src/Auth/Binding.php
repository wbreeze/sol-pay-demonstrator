<?php

declare(strict_types=1);

namespace Newsprint\Auth;

/**
 * What a session row says about the browser holding it (SPEC §5.3): the meter
 * it uses, that meter's fund, and the browser key that was proven.
 *
 * Three addresses and nothing else. The meter is the integrator's one
 * obligation under the fund design, to remember which meter a browser uses.
 * The fund's address is kept because it saves a read: the charge needs the
 * fund's token account in the same call as the meter, and the meter would
 * otherwise have to be read first to learn which fund it names. The key is
 * kept so that every read can ask whether the meter still names it.
 *
 * A publisher with accounts would put these on the account row instead of a
 * session, and nothing that takes a `Binding` would change.
 */
final class Binding
{
    public function __construct(
        public readonly string $meter,
        public readonly string $fund,
        public readonly string $key,
    ) {
    }
}
