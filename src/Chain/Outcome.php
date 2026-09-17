<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Cause;

/** What a submission came to. Carries a `Cause` and a signature — never logs (§8.1). */
final class Outcome
{
    private function __construct(
        public readonly SubmitStatus $status,
        public readonly ?string $signature,
        public readonly ?Cause $cause,
        public readonly string $detail,
    ) {
    }

    public static function confirmed(string $signature): self
    {
        return new self(SubmitStatus::Confirmed, $signature, null, 'confirmed');
    }

    public static function unconfirmed(string $signature): self
    {
        return new self(SubmitStatus::Unconfirmed, $signature, null, 'not confirmed inside the window');
    }

    /**
     * Accepted by the endpoint and not waited for. Acceptance is not nothing:
     * `sendTransaction` simulates first (no `skipPreflight`), so a charge the
     * program or SPL would refuse against the state the endpoint holds is
     * already a `failed()` by the time this could be returned.
     */
    public static function sent(string $signature): self
    {
        return new self(SubmitStatus::Sent, $signature, null, 'sent; not waited for');
    }

    public static function failed(?string $signature, ?Failure $failure): self
    {
        return new self(
            SubmitStatus::Failed,
            $signature,
            $failure?->cause,
            $failure?->message ?? 'transaction failed',
        );
    }

    public function ok(): bool
    {
        return $this->status !== SubmitStatus::Failed;
    }
}
