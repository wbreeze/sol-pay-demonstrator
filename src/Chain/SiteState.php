<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Site;
use SolPay\Core\TokenAccount;

/**
 * What one read of the chain says about this site, decoded.
 *
 * SPEC §2's claim 7 is that every number on the screen came from an account
 * rather than from the server's memory, and this is the object that makes the
 * claim checkable: the site's prices are read back from the `Site` account on
 * every request, not taken from `config/site.php`. The config decides what to
 * write at setup; after that the chain is the authority, and if the two
 * disagree the inspector says so rather than quietly showing the one that
 * agrees with itself.
 */
final class SiteState
{
    public function __construct(
        public readonly string $address,
        public readonly Site $site,
        public readonly ?TokenAccount $treasury,
        public readonly ?int $mintDecimals,
    ) {
    }

    /** The treasury's balance in base units, or null when the account could not be read. */
    public function treasuryBalance(): ?int
    {
        return $this->treasury?->amount;
    }
}
