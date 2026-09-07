<?php

declare(strict_types=1);

namespace Newsprint\Auth;

/**
 * A sign-in that will not be granted, with a reason a screen can show.
 *
 * SPEC §8's rule — every branch that leaves the happy path early is a screen,
 * not an error page — applies here as much as it does on the metering path. A
 * reader whose wallet is on the wrong chain, or who took twenty minutes over
 * the approval dialog, is owed a sentence rather than a 500.
 */
final class SignInException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'rejected',
    ) {
        parent::__construct($message);
    }
}
