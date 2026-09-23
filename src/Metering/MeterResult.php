<?php

declare(strict_types=1);

namespace Newsprint\Metering;

use Newsprint\Chain\PayerState;
use SolPay\Core\Blocked;
use SolPay\Core\Cause;
use SolPay\Core\Instruction;
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
        /**
         * Whether this site's contract was the delegate on the reader's token
         * account when the charge was refused. Null where the question was not
         * asked, which is every outcome that did not read the account.
         *
         * Separate from the shortfall's own `delegatePresent`, which answers
         * `delegate !== null` and is therefore true of another site's delegate
         * as well as of this one's.
         */
        public readonly ?bool $delegateIsContract = null,
        public readonly string $detail = '',
        /** What this call would charge, in base units. */
        public readonly int $charge = 0,
        /**
         * Whether the call moved money. A prediction from the preflight on the
         * request that sent it; read from the landed event on a request that
         * reports it afterwards; null when that read did not answer.
         */
        public readonly ?bool $settles = false,
        public readonly int $pageViews = 1,
        /**
         * The instructions this call built, exactly as `SolPay\Core\Ix`
         * returned them, for SPEC §9's last section.
         *
         * They are carried rather than rebuilt for display, and that is the
         * whole point: §9 wants the instruction bytes shown *beside* the
         * transaction as "a live check on the library's claim that its output
         * drops straight into a transaction message". A panel that rebuilt
         * them from the same inputs would agree with itself no matter what was
         * sent, which is not a check of anything.
         *
         * Empty on every outcome that sent nothing — a live grant, a blocked
         * preflight, an unreadable endpoint. There is no transaction to be the
         * last one.
         *
         * @var list<Instruction>
         */
        public readonly array $instructions = [],
        /**
         * The accounts the decision was made from, on the outcomes that sent
         * nothing — and null on every outcome that sent something.
         *
         * `meter()` reads the contract and the reader's token account to
         * decide, and the request then renders from those same two accounts.
         * Where no transaction went out, the read that decided is still the
         * truth when the page is drawn, and reading it again is a second
         * ~500 ms round trip for the same bytes. Measured 2026-09-09: the
         * `set-meter` screen cost three `getMultipleAccounts` and 1.88 s, of
         * which one whole call produced a value that was discarded.
         *
         * **Null wherever a transaction was sent, and that is the safety
         * property rather than an omission.** `metered`, `unconfirmed` and
         * `failed` all follow a `sendTransaction`; `used`, `paid` and the
         * carried residue may have moved, and §2's claim 7 is that every
         * number on the screen came from an account rather than from the
         * server's memory. Carrying a pre-send read onto one of those screens
         * would show a reader stale arithmetic that looks exactly like fresh
         * arithmetic.
         */
        public readonly ?PayerState $payer = null,
        /**
         * What became of the charge the article is served on — the grant's own
         * record, or `Pending` on the request that has just sent it.
         */
        public readonly ChargeState $chargeState = ChargeState::Confirmed,
        /**
         * This request is reporting a transaction an earlier request sent.
         * Its instructions were built there and are not here, and §9's panel
         * says so rather than claiming a browser built them.
         */
        public readonly bool $earlier = false,
    ) {
    }

    /**
     * Served without touching the chain *to decide*: a live grant already
     * covers it (§7.1). The grant's charge comes with it, because a grant can
     * now be older than the answer about the charge that bought it.
     * `$asked` says this request put one question to the chain about that
     * charge — the only chain call a granted view can make.
     */
    public static function granted(ChargeState $charge = ChargeState::Confirmed, bool $asked = false): self
    {
        return new self(MeterOutcome::Granted, detail: 'a live grant already covered this article', chargeState: $charge, earlier: $asked);
    }

    /**
     * §7.3 since 2026-09-17: the endpoint accepted the charge and the article
     * is served now. Nobody has asked the cluster yet; a later request does.
     *
     * @param list<Instruction> $instructions
     */
    public static function servedAhead(string $signature, int $charge, bool $settles, array $instructions = []): self
    {
        return new self(MeterOutcome::Sent, signature: $signature, detail: 'sent; the article was served before the chain confirmed', charge: $charge, settles: $settles, instructions: $instructions, chargeState: ChargeState::Pending);
    }

    /**
     * An earlier request's charge, found to have landed.
     *
     * `$settles` is read from the landed event rather than carried from the
     * request that predicted it — that request's answer is gone, and a value
     * the browser sent back would be the reader's word for it.
     */
    public static function confirmedLater(string $signature, ?bool $settles, int $pageViews = 1): self
    {
        return new self(MeterOutcome::Metered, signature: $signature, detail: 'confirmed after the article was served', settles: $settles, pageViews: $pageViews, earlier: true);
    }

    /** An earlier request's charge, still without an answer — or never going to have one. */
    public static function unconfirmedLater(string $signature, ChargeState $charge): self
    {
        return new self(MeterOutcome::Unconfirmed, signature: $signature, detail: $charge === ChargeState::Unknown ? 'never seen on chain; it can no longer land' : 'sent; not confirmed inside the window', settles: null, chargeState: $charge, earlier: true);
    }

    /**
     * An earlier request's charge, found to have landed and failed. The
     * article was already served, the grant stays, and nothing was charged.
     */
    public static function absorbed(string $signature, ?Cause $cause, string $detail): self
    {
        return new self(MeterOutcome::Absorbed, signature: $signature, cause: $cause, detail: $detail, settles: null, chargeState: ChargeState::Refused, earlier: true);
    }

    /** @param list<Instruction> $instructions */
    public static function metered(string $signature, int $charge, bool $settles, int $pageViews = 1, array $instructions = []): self
    {
        return new self(MeterOutcome::Metered, signature: $signature, detail: 'confirmed', charge: $charge, settles: $settles, pageViews: $pageViews, instructions: $instructions);
    }

    /**
     * §7.3: sent, not confirmed inside the window, served anyway and flagged.
     * The site absorbs the cheaper of two asymmetric errors.
     */
    /** @param list<Instruction> $instructions */
    public static function unconfirmed(string $signature, int $charge, bool $settles, int $pageViews = 1, array $instructions = []): self
    {
        return new self(MeterOutcome::Unconfirmed, signature: $signature, detail: 'sent; not confirmed inside the window', charge: $charge, settles: $settles, pageViews: $pageViews, instructions: $instructions, chargeState: ChargeState::Pending);
    }

    /** The preflight refused before anything was signed — `can_meter` said no. */
    public static function blocked(Blocked $blocked, int $charge, ?PayerState $payer = null): self
    {
        return new self(MeterOutcome::Blocked, blocked: $blocked, detail: (string) $blocked, charge: $charge, payer: $payer);
    }

    /** The chain refused. */
    /** @param list<Instruction> $instructions */
    public static function failed(string $detail, ?Cause $cause, ?Shortfall $shortfall, ?string $signature = null, array $instructions = [], ?bool $delegateIsContract = null): self
    {
        return new self(
            MeterOutcome::Failed,
            signature: $signature,
            cause: $cause,
            shortfall: $shortfall,
            delegateIsContract: $delegateIsContract,
            detail: $detail,
            instructions: $instructions,
        );
    }

    /**
     * The endpoint did not answer, or there was nothing to meter against.
     * Nothing was sent and nothing is owed.
     *
     * The payer is carried when there is one — "this reader has no contract"
     * is a conclusion drawn *from* a successful read, not a failure to read.
     */
    public static function unreadable(string $detail, ?PayerState $payer = null): self
    {
        return new self(MeterOutcome::Unreadable, detail: $detail, payer: $payer);
    }

    /**
     * Did a transaction go out?
     *
     * Distinct from `serves()`, and the difference is `Failed`: a refused
     * charge serves nothing and still moved the chain far enough that `used`,
     * `paid` and the carried residue must be read again before they are shown.
     * {@see \Newsprint\Chain\RequestRead::invalidatePayer()} is the caller.
     */
    public function sent(): bool
    {
        return in_array($this->outcome, [MeterOutcome::Metered, MeterOutcome::Unconfirmed, MeterOutcome::Failed, MeterOutcome::Sent, MeterOutcome::Absorbed], true);
    }

    /** Is the reader entitled to the body? */
    public function serves(): bool
    {
        return in_array($this->outcome, [MeterOutcome::Granted, MeterOutcome::Metered, MeterOutcome::Unconfirmed, MeterOutcome::Sent, MeterOutcome::Absorbed], true);
    }

    /**
     * Has the chain still not said whether the charge landed?
     *
     * While it has not, no account read describes the charge — a read at
     * `confirmed` taken now would show the numbers from before it — so the
     * screens show none of them (§2's claim 7, 2026-09-17).
     */
    public function awaiting(): bool
    {
        return $this->chargeState === ChargeState::Pending;
    }

    /**
     * Should the page ask again by itself? Only where nothing has asked for a
     * window yet: the charge this request sent, or a grant still pending. A
     * report that already waited the window out is left for a reload.
     */
    public function asksAgain(): bool
    {
        return $this->awaiting() && in_array($this->outcome, [MeterOutcome::Sent, MeterOutcome::Granted], true);
    }
}
