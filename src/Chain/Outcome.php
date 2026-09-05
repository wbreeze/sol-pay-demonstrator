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
