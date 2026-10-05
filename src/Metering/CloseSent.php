<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use SolPay\Core\Instruction;

/**
 * A close this request sent, for SPEC §9.2's panel.
 *
 * Not a {@see MeterResult}, because a close is not a metering decision: it
 * charges nothing, counts no items, and its answer is an account that is no
 * longer there (SPEC §5.4).
 *
 * The instructions are read out of the message this site kept for the key to
 * sign ({@see \Newsprint\Chain\MessageSigner::instructions()}). The request
 * that composed the close is not the one that sends it, and the message is
 * the only thing kept between the two. So the panel shows what was signed and
 * sent, and says that is what it shows.
 */
final class CloseSent
{
    /** @param list<Instruction> $instructions */
    public function __construct(
        public readonly string $signature,
        public readonly array $instructions,
        /** The browser key that signed. The meter that named it is gone, so the panel is told. */
        public readonly string $key,
        /** Whether a read after the send found the meter account gone. */
        public readonly bool $landed,
    ) {
    }
}
