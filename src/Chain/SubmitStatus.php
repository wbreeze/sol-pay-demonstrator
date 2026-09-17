<?php

declare(strict_types=1);

namespace Newsprint\Chain;

/**
 * The three outcomes SPEC §7.3 distinguishes, and a fourth that is a choice
 * not to wait for them. `Unconfirmed` is not a failure
 * and not a success: the transaction was sent, the confirmation did not arrive
 * inside the window, and it may or may not have landed. The site's policy for
 * that case is the caller's to apply, not this layer's — §7.3 says serve the
 * article and flag it, and says why the two errors are not symmetric.
 *
 * `Sent` is the article charge's since 2026-09-17: the endpoint accepted the
 * transaction — which means its simulation passed — and nobody has asked the
 * cluster about it yet. §7.3's order is serve first and confirm afterward, so
 * the asking is a later request's job.
 */
enum SubmitStatus
{
    case Confirmed;
    case Unconfirmed;
    case Failed;
    case Sent;
}
