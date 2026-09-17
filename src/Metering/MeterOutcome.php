<?php

declare(strict_types=1);

namespace Newsprint\Metering;

/**
 * The eight things that can happen at the metering step, or after it.
 *
 * Five of them serve the article. `Unconfirmed` is one of the five, and that
 * is SPEC §7.3's decision rather than an oversight: refusing to serve risks
 * charging a reader for nothing, serving risks giving away one article at
 * `page_price`, and the site absorbs the cheaper error deliberately.
 *
 * `Sent` and `Absorbed` arrived with serve-first (2026-09-17). An article's
 * charge is `Sent` when the endpoint accepts it, and the body is served then.
 * A later request finds out the rest: `Metered` when it landed, `Unconfirmed`
 * when nobody can yet say, and `Absorbed` when it landed and failed — the
 * reader keeps the article and the site keeps the loss.
 */
enum MeterOutcome: string
{
    case Granted = 'granted';
    case Metered = 'metered';
    case Unconfirmed = 'unconfirmed';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Unreadable = 'unreadable';
    case Sent = 'sent';
    case Absorbed = 'absorbed';
}
