<?php

declare(strict_types=1);

namespace Newsprint\Support;

/**
 * Short, stable names for addresses (SPEC §9).
 *
 * Base58 is unreadable and, worse, *comparable-looking*: two addresses sharing
 * four leading characters read as the same address to a human eye scanning a
 * panel. So every address the site shows gets a role prefix and a nonsense
 * syllable — `CPDAcat`, `PAYRfig` — with the full base58 one click away and
 * always what gets copied.
 *
 * **The syllable is derived from the address, not assigned in order.** The same
 * address draws the same alias for every reader, in every session, and after a
 * redeploy. An alias that is stable is something two people can say to each
 * other on a call while looking at their own screens; an alias that is
 * positional is a lie the first time the panel reorders.
 *
 * These are this site's invention and not a standard, which the inspector says
 * once, so nobody leaves thinking `CPDAcat` means anything to a wallet or an
 * explorer.
 */
final class Alias
{
    public const PROGRAM = 'PID';
    public const SITE = 'SPDA';
    public const CONTRACT = 'CPDA';
    public const MINT = 'MINT';
    public const TREASURY = 'TRSY';
    public const PAYER = 'PAYR';
    public const PAYER_TOKEN_ACCOUNT = 'PATA';
    public const TOKEN_PROGRAM = 'TKPG';

    /**
     * The site authority (SPEC §4.4), added 2026-09-08 — the one address that
     * appears in three sections at once and had no short name in any of them.
     * It is the site account's `authority`, it is very likely the treasury
     * token account's `owner`, and it is the fee payer that signs every
     * metering call. Three sightings of one 44-character string is exactly the
     * case §9's comparable-looking argument is about.
     */
    public const AUTHORITY = 'AUTH';

    /**
     * An address this panel cannot place, and the instruction data.
     *
     * Until 2026-09-12 an address with no role in the map was rendered bare,
     * and the reason was good: a name invented on the spot for an unknown role
     * would have looked exactly like the stable kind. **A distinct prefix is
     * what makes it safe to invent one** — `ACCT` says only "an account this
     * panel cannot name", which is the truth, and it is still derived from the
     * address, so it is as stable as any other. What made it necessary is the
     * table: with the addresses gathered at the top and short names used
     * everywhere else, an address with no short name has nowhere to be
     * defined.
     *
     * `DATA` is the same argument for the one value in the panel that is not
     * an address and is just as unreadable — an instruction's Borsh bytes.
     */
    public const UNNAMED = 'ACCT';
    public const DATA = 'DATA';

    /**
     * Sixty-four syllables, so one byte of the hash chooses one with no
     * modulo bias. Pronounceable, short, and meaningless — a syllable that
     * looked like a word would invite someone to read significance into it.
     */
    private const SYLLABLES = [
        'ash', 'bel', 'cat', 'dim', 'elk', 'fig', 'gil', 'hox',
        'ibi', 'jot', 'kip', 'lem', 'mor', 'nib', 'oat', 'pep',
        'qua', 'rho', 'sil', 'tum', 'urn', 'vex', 'wik', 'xan',
        'yap', 'zed', 'arc', 'bod', 'cur', 'dap', 'emu', 'fen',
        'gob', 'hem', 'inn', 'jib', 'ken', 'lox', 'mux', 'nap',
        'obi', 'pug', 'qod', 'rif', 'sod', 'tal', 'ulm', 'vim',
        'wob', 'xis', 'yel', 'zub', 'ant', 'bry', 'cob', 'dol',
        'eft', 'fop', 'gnu', 'hab', 'ilk', 'jog', 'kob', 'lud',
    ];

    public static function for(string $role, string $address): string
    {
        $byte = ord(hash('sha256', $address, true)[0]);

        return $role.self::SYLLABLES[$byte % count(self::SYLLABLES)];
    }
}
