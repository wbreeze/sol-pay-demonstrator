<?php

declare(strict_types=1);

namespace Newsprint\Chain;

/**
 * The three outcomes SPEC §7.3 distinguishes. `Unconfirmed` is not a failure
 * and not a success: the transaction was sent, the confirmation did not arrive
 * inside the window, and it may or may not have landed. The site's policy for
 * that case is the caller's to apply, not this layer's — §7.3 says serve the
 * article and flag it, and says why the two errors are not symmetric.
 */
enum SubmitStatus
{
    case Confirmed;
    case Unconfirmed;
    case Failed;
}
