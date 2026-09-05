<?php

declare(strict_types=1);

namespace Newsprint\Setup;

/**
 * One thing setup did, or did not have to do. The screen renders these in
 * order, which is the whole of its reporting: an operator who has just handed
 * a program authority over their money should be able to see what was created
 * and what was already there.
 */
final class Step
{
    private function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $detail,
        public readonly ?string $signature = null,
        public readonly ?string $address = null,
    ) {
    }

    public static function done(string $name, string $detail, ?string $signature = null, ?string $address = null): self
    {
        return new self($name, 'done', $detail, $signature, $address);
    }

    /** Already true on chain. A resumed run is mostly these. */
    public static function already(string $name, string $detail, ?string $address = null): self
    {
        return new self($name, 'already', $detail, null, $address);
    }

    /** Setup cannot continue, and the detail says what the operator should do. */
    public static function blocked(string $name, string $detail, ?string $address = null): self
    {
        return new self($name, 'blocked', $detail, null, $address);
    }

    public static function failed(string $name, string $detail, ?string $signature = null): self
    {
        return new self($name, 'failed', $detail, $signature);
    }

    public function stops(): bool
    {
        return $this->status === 'blocked' || $this->status === 'failed';
    }
}
