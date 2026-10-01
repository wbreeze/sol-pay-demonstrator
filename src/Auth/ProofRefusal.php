<?php

declare(strict_types=1);

namespace Newsprint\Auth;

/**
 * Why a key proof was refused, in the order SPEC §5.2 checks (§5.2's list,
 * with the meter's absence where the second check would read it).
 *
 * Each case is a sentence the page can show. None says more than the reader
 * could find on the chain themselves.
 */
enum ProofRefusal: string
{
    case Nonce = 'nonce';
    case NoMeter = 'no-meter';
    case Signature = 'signature';
    case AnotherSite = 'another-site';

    public function sentence(): string
    {
        return match ($this) {
            self::Nonce => 'That proof used a nonce this site did not issue, or one already used or more than five minutes old.',
            self::NoMeter => 'There is no meter at that address: it has been closed, or was never opened.',
            self::Signature => 'The proof is not signed by the key this meter answers to. If another device renewed the meter, that device holds it now.',
            self::AnotherSite => 'That meter belongs to another site.',
        };
    }
}
