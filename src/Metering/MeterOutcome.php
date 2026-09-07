<?php

declare(strict_types=1);

namespace Newsprint\Metering;

/**
 * The six things that can happen at the metering step.
 *
 * Three of them serve the article. `Unconfirmed` is one of the three, and that
 * is SPEC §7.3's decision rather than an oversight: refusing to serve risks
 * charging a reader for nothing, serving risks giving away one article at
 * `page_price`, and the site absorbs the cheaper error deliberately.
 */
enum MeterOutcome: string
{
    case Granted = 'granted';
    case Metered = 'metered';
    case Unconfirmed = 'unconfirmed';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Unreadable = 'unreadable';
}
