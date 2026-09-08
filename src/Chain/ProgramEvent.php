<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Base58;

/**
 * One `Metered`, `Renewed` or `Closed` event, decoded (SPEC §9's last
 * section).
 *
 * **Why this is here and not in the library.** `php-client` builds
 * instructions and decodes accounts; it does not decode events, and nothing
 * about that is an oversight — an event is not on the read path of a metering
 * call. This site wants one anyway, because §9 asks the panel to show what the
 * program *said* it did beside what this server said it did, and those are two
 * different claims. When they agree the demo has shown its work. When they do
 * not, the chain is right.
 *
 * **The format is Anchor's, not Solana's.** `emit!` writes one line into the
 * program logs: the literal `Program data: `, then base64 of an 8-byte
 * discriminator — `sha256("event:<Name>")[..8]` — followed by the fields in
 * declaration order, Borsh-encoded. Nothing in the runtime enforces that
 * shape; it is a convention of the framework the program was written with, so
 * the discriminators below are constants of *this* program's build and a
 * rename upstream changes them silently. `EventDecodeTest` pins each one
 * against the hash it comes from, which is the cheapest way to notice.
 *
 * **§8.1 is not being bent here.** That rule keeps the payer's address out of
 * anything this site shows, and logs are where the address lives. This class
 * is given the whole log array and returns exactly one decoded event with
 * three to five integer fields and a contract address; every other line is
 * dropped in this file and never reaches a template. The contract PDA is
 * derived from the reader's own wallet, so it is theirs, and it is already on
 * the screen two sections above.
 */
final class ProgramEvent
{
    /**
     * `sha256("event:<Name>")[..8]`, and the field layout that follows it.
     *
     * Sizes are Borsh's, which for these types is little-endian and fixed
     * width: `Pubkey` is 32 raw bytes, `u32` is 4, `u64` is 8. There is
     * nothing variable-length in any of the three, so a length mismatch is a
     * decode failure rather than something to parse around.
     *
     * @var array<string, array{discriminator: string, fields: array<string, string>}>
     */
    private const EVENTS = [
        'Metered' => [
            'discriminator' => '1e8e96a17c2e1d7e',
            'fields' => ['contract' => 'pubkey', 'page_views' => 'u32', 'used' => 'u64', 'paid' => 'u64', 'transferred' => 'u64'],
        ],
        'Renewed' => [
            'discriminator' => '8bfcd923492a0757',
            'fields' => ['contract' => 'pubkey', 'limit' => 'u64', 'carried' => 'u64'],
        ],
        'Closed' => [
            'discriminator' => '321f579b87dcc3ef',
            'fields' => ['contract' => 'pubkey', 'forgiven' => 'u64'],
        ],
    ];

    private const WIDTH = ['pubkey' => 32, 'u32' => 4, 'u64' => 8];

    /**
     * @param array<string, int|string> $fields in the program's declaration
     *                                          order, `contract` as base58 and
     *                                          the rest as base units
     */
    private function __construct(
        public readonly string $name,
        public readonly string $contract,
        public readonly array $fields,
    ) {
    }

    /**
     * The first line that decodes to one of this program's events, or null.
     *
     * "The first" rather than "the only" because a transaction may carry more
     * than one program's data lines and a future instruction may emit twice;
     * a metering call emits once. Anything that is not one of the three
     * discriminators is not this program's event and is skipped rather than
     * guessed at — including another program's `Program data:`, which is the
     * case that makes the discriminator check load-bearing rather than
     * decorative.
     *
     * @param list<string> $logs
     */
    public static function fromLogs(array $logs): ?self
    {
        foreach ($logs as $line) {
            if (!str_starts_with($line, 'Program data: ')) {
                continue;
            }
            $raw = base64_decode(substr($line, 14), true);
            if ($raw === false) {
                continue;
            }
            $event = self::decode($raw);
            if ($event !== null) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Discriminator plus Borsh body, or null.
     *
     * Null covers three different things on purpose — not ours, truncated,
     * longer than the layout accounts for — because the site's response to all
     * three is identical: say the event could not be read rather than show a
     * number that came from the wrong bytes. A partial decode would be worse
     * than none, since §9's whole reason for showing this is that it can be
     * checked.
     */
    public static function decode(string $bytes): ?self
    {
        if (strlen($bytes) < 8) {
            return null;
        }
        $discriminator = bin2hex(substr($bytes, 0, 8));

        foreach (self::EVENTS as $name => $layout) {
            if ($layout['discriminator'] !== $discriminator) {
                continue;
            }

            $expected = 8;
            foreach ($layout['fields'] as $type) {
                $expected += self::WIDTH[$type];
            }
            if (strlen($bytes) !== $expected) {
                return null;
            }

            $at = 8;
            $fields = [];
            foreach ($layout['fields'] as $field => $type) {
                $chunk = substr($bytes, $at, self::WIDTH[$type]);
                $at += self::WIDTH[$type];

                if ($type === 'pubkey') {
                    $fields[$field] = Base58::encode($chunk);
                    continue;
                }

                // `V` is a little-endian u32. `P` is little-endian and
                // *signed*, because PHP has no unsigned 64-bit integer: a
                // value past PHP_INT_MAX comes back negative, and the honest
                // report is that it is out of range rather than a number with
                // the sign flipped. `Preflight`'s docblock draws the same
                // ceiling for the same reason; ordinary token amounts are
                // nowhere near it. The negative check below is what acts on it.
                $unpacked = unpack($type === 'u32' ? 'V' : 'P', $chunk);
                if ($unpacked === false) {
                    return null;
                }
                $fields[$field] = (int) $unpacked[1];
            }

            foreach ($fields as $value) {
                if (is_int($value) && $value < 0) {
                    return null;
                }
            }

            return new self($name, (string) $fields['contract'], $fields);
        }

        return null;
    }

    /** The discriminator this class expects for an event, for the test that pins them. */
    public static function discriminatorFor(string $name): ?string
    {
        return self::EVENTS[$name]['discriminator'] ?? null;
    }

    /** @return list<string> the event names this decoder knows */
    public static function known(): array
    {
        return array_keys(self::EVENTS);
    }
}
