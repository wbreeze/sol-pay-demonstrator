<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use SolPay\Core\Blocked;
use SolPay\Core\Cause;
use SolPay\Core\Shortfall;

/**
 * What the metering decision came to, for one request.
 *
 * Every value the screens and the inspector need, and nothing else — in
 * particular no logs, per §8.1: `Failure` has already reduced them to a
 * `Cause` and dropped the rest, and this object is downstream of that.
 */
final class MeterResult
{
    private function __construct(
        public readonly MeterOutcome $outcome,
        public readonly ?string $signature = null,
        /** Why the preflight said no, before anything was sent. */
        public readonly ?Blocked $blocked = null,
        /** Why the chain said no, after it was. */
        public readonly ?Cause $cause = null,
        /** Which constraint on the token account is short (§8.2's ambiguity). */
        public readonly ?Shortfall $shortfall = null,
        public readonly string $detail = '',
        /** What this call would charge, in base units. */
        public readonly int $charge = 0,
        public readonly bool $settles = false,
        public readonly int $pageViews = 1,
    ) {
    }

    /** Served without touching the chain: a live grant already covers it (§7.1). */
    public static function granted(): self
    {
        return new self(MeterOutcome::Granted, detail: 'a live grant already covered this article');
    }

    public static function metered(string $signature, int $charge, bool $settles, int $pageViews = 1): self
    {
        return new self(MeterOutcome::Metered, signature: $signature, detail: 'confirmed', charge: $charge, settles: $settles, pageViews: $pageViews);
    }

    /**
     * §7.3: sent, not confirmed inside the window, served anyway and flagged.
     * The site absorbs the cheaper of two asymmetric errors.
     */
    public static function unconfirmed(string $signature, int $charge, bool $settles, int $pageViews = 1): self
    {
        return new self(MeterOutcome::Unconfirmed, signature: $signature, detail: 'sent; not confirmed inside the window', charge: $charge, settles: $settles, pageViews: $pageViews);
    }

    /** The preflight refused before anything was signed — `can_meter` said no. */
    public static function blocked(Blocked $blocked, int $charge): self
    {
        return new self(MeterOutcome::Blocked, blocked: $blocked, detail: (string) $blocked, charge: $charge);
    }

    /** The chain refused. */
    public static function failed(string $detail, ?Cause $cause, ?Shortfall $shortfall, ?string $signature = null): self
    {
        return new self(MeterOutcome::Failed, signature: $signature, cause: $cause, shortfall: $shortfall, detail: $detail);
    }

    /** The endpoint did not answer. Nothing was sent and nothing is owed. */
    public static function unreadable(string $detail): self
    {
        return new self(MeterOutcome::Unreadable, detail: $detail);
    }

    /** Is the reader entitled to the body? */
    public function serves(): bool
    {
        return in_array($this->outcome, [MeterOutcome::Granted, MeterOutcome::Metered, MeterOutcome::Unconfirmed], true);
    }
}
