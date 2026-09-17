<?php

declare(strict_types=1);

namespace Newsprint\Metering;

/**
 * What became of the charge a grant was bought with (SPEC §7.3, 2026-09-17).
 *
 * The article is served as soon as the endpoint accepts the charge, so a
 * grant can exist before anybody knows whether its transaction landed. This
 * is the answer, kept on the grant row and nowhere else, and it goes when the
 * grant does.
 *
 * Every state but `Pending` is final, and every one of them keeps the grant.
 * That is the policy, written down: the reader already has the article, and
 * taking it back after the fact is the mysterious experience §7.3 exists to
 * avoid. What the site loses on `Refused` and `Unknown` is one page price and,
 * on `Refused`, the fee.
 */
enum ChargeState: string
{
    /** Landed. The ordinary case, and the default for a grant written before this column existed. */
    case Confirmed = 'confirmed';

    /** Accepted by the endpoint; the cluster has not answered yet. */
    case Pending = 'pending';

    /** Landed and failed: the state moved between the endpoint's simulation and inclusion. */
    case Refused = 'refused';

    /**
     * Never seen, and past the point where it could still land. A transaction
     * is dead once its blockhash has expired, so after the settle window a
     * charge the cluster still does not know is taken as dropped.
     */
    case Unknown = 'unknown';
}
