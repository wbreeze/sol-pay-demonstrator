<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Blocked;
use SolPay\Core\Contract;
use SolPay\Core\Preflight;
use SolPay\Core\Site;
use SolPay\Core\TokenAccount;

/**
 * What one read of the chain says about this reader, decoded.
 *
 * This is the `identified` branch of sol-pay's state diagram made concrete.
 * The diagram's choice is not "has this person authenticated" but "is this
 * viewer's wallet address known" — and once it is, `find_contract` derives the
 * contract address from the site and that address and reads it. A reader who
 * authorized last week and arrives today with an empty cookie jar signs in and
 * lands on their existing contract, because the contract lives on chain and
 * the session was only ever the map to it.
 *
 * Nothing here is remembered. The site stores a wallet address against a
 * session and reads everything else back from accounts, every request.
 */
final class PayerState
{
    public function __construct(
        public readonly string $wallet,
        public readonly string $contractAddress,
        public readonly string $tokenAccount,
        public readonly ?Contract $contract,
        public readonly ?TokenAccount $funds,
        private readonly Site $site,
        public readonly int $decimals,
    ) {
    }

    /** No contract: the diagram's route to `set_meter`. */
    public function hasContract(): bool
    {
        return $this->contract !== null;
    }

    /**
     * No token account at all, or one with nothing in it.
     *
     * Worth separating from "no contract", because they are different
     * conversations: one is "choose a limit", the other is "you have no DEMO,
     * and here is where it comes from". `approve_checked` against a token
     * account that does not exist fails at the runtime, so this is checked
     * before the reader is asked to sign anything.
     */
    public function isFunded(): bool
    {
        return $this->funds !== null && $this->funds->amount > 0;
    }

    public function balance(): int
    {
        return $this->funds?->amount ?? 0;
    }

    /** The smallest limit this reader may authorize now (§4.2's `min_limit`, or the renewal floor). */
    public function limitFloor(): int
    {
        return Preflight::limitFloor($this->site, $this->contract);
    }

    /** How many more views fit under the limit, or null with no contract. */
    public function viewsRemaining(): ?int
    {
        return $this->contract === null ? null : Preflight::viewsRemaining($this->contract, $this->site);
    }

    /**
     * Whether one more page view would go through, or why it would not.
     * Null means it would.
     */
    public function blocked(int $pageViews = 1): ?Blocked
    {
        return $this->contract === null ? null : Preflight::canMeter($this->contract, $this->site, $pageViews);
    }

    /**
     * The allowance `approve_checked` must carry for a given limit. The
     * delegate can only draw what it was approved for, so authorizing a limit
     * without the matching allowance produces a contract that cannot settle.
     */
    public function requiredAllowance(int $limit): int
    {
        return Preflight::requiredAllowance($limit);
    }
}
