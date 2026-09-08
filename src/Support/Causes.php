<?php

declare(strict_types=1);

namespace Newsprint\Support;

use SolPay\Core\Cause;
use SolPay\Core\CauseKind;

/**
 * A `Cause`, in a sentence a reader can act on.
 *
 * `Cause` is a value object with a kind and one of three payloads; it is
 * deliberately not stringable, because "what raised this" and "what should a
 * reader be told" are different questions and the library declines to answer
 * the second one. This class answers it, for this site, in this site's voice —
 * which is the shape of nearly every decision at this boundary.
 *
 * SPEC §8.1's rule still holds through here: what arrives is a `Cause` that
 * {@see \Newsprint\Chain\Failure} already reduced from the logs, so there is
 * nothing left in it to leak. The reader's own logs stay on the explorer,
 * where they belong.
 */
final class Causes
{
    public static function describe(?Cause $cause): ?string
    {
        if ($cause === null) {
            return null;
        }

        return match ($cause->kind) {
            CauseKind::Program => $cause->payError === null
                ? 'this site\'s metering program refused the call'
                : sprintf('%s (%d) — %s', $cause->payError->name, $cause->payError->code(), $cause->payError->message()),

            CauseKind::Token => $cause->tokenError === null
                ? 'the token program refused the transfer'
                : sprintf('SPL Token %s (%d) — %s', $cause->tokenError->name, $cause->tokenError->code(), $cause->tokenError->message()),

            // §8.2's last row: the program address and the code, and no guess.
            // The runtime can surface errors from programs neither this site
            // nor the library anticipated, and naming one would be a lie.
            CauseKind::Unknown => sprintf(
                'code %s from %s — a program neither this site nor sol-pay names',
                $cause->unknownCode === null ? '?' : (string) $cause->unknownCode,
                $cause->unknownProgram ?? 'an unnamed program',
            ),
        };
    }
}
