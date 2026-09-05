<?php

declare(strict_types=1);

namespace Newsprint\Chain;

/**
 * An RPC call that did not return a result. Carries a {@see Failure} when the
 * node said why in a form worth attributing — never raw logs, per SPEC §8.1.
 */
final class RpcException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?Failure $failure = null,
    ) {
        parent::__construct($message);
    }
}
