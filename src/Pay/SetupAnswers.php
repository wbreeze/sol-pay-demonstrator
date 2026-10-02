<?php

declare(strict_types=1);

namespace Newsprint\Pay;

/**
 * What the panel asked, for one scan (SPEC §6.3 step 1, §6.4).
 *
 * Two kinds. A **setup** opens or renews this site's meter on fund `index`,
 * naming the browser's `key`, with a `limit` and an `expiry`, and deposits
 * `deposit` on the way; whether it opens or renews is the chain's to say when
 * the wallet asks. A **deposit** is *add to the fund*: the transfer alone,
 * touching no meter (§6.4).
 *
 * `fund` is set when the setup started from a session, which knows its fund:
 * a renewal from `manage_meter`, or a deposit. The wallet that scans must then
 * be the one that fund names, and the composer refuses any other.
 */
final class SetupAnswers
{
    /** The expiry choices, decided 2026-10-01: never a date to type. */
    public const EXPIRIES = [
        'hour' => 3_600,
        'day' => 86_400,
        'week' => 604_800,
        'month' => 2_592_000,
    ];

    public const SETUP = 'setup';
    public const DEPOSIT = 'deposit';

    public function __construct(
        public readonly string $kind,
        public readonly int $index,
        public readonly int $deposit,
        public readonly int $limit = 0,
        public readonly string $expiry = 'day',
        public readonly ?string $key = null,
        public readonly ?string $fund = null,
    ) {
    }

    /** Seconds from the moment of composing. */
    public function expirySeconds(): int
    {
        return self::EXPIRIES[$this->expiry];
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'index' => $this->index,
            'deposit' => $this->deposit,
            'limit' => $this->limit,
            'expiry' => $this->expiry,
            'key' => $this->key,
            'fund' => $this->fund,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            kind: (string) $row['kind'],
            index: (int) $row['index'],
            deposit: (int) $row['deposit'],
            limit: (int) ($row['limit'] ?? 0),
            expiry: (string) ($row['expiry'] ?? 'day'),
            key: isset($row['key']) ? (string) $row['key'] : null,
            fund: isset($row['fund']) ? (string) $row['fund'] : null,
        );
    }
}
