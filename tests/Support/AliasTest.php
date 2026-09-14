<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Support\Alias;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §9: an alias that is stable is something two people can say to each
 * other on a call while looking at their own screens; an alias that is
 * positional is a lie the first time the panel reorders. So stability is the
 * property worth a test.
 */
final class AliasTest extends TestCase
{
    private const SITE = 'F8UDAGgxVTm8Vmh4RmskpMBCFqhRvuTqbDxDCj8UMedL';
    private const PAYER = '163aJWGmry7Q2gWjtxmTbdC7NGFc7FecSN1gfpNUgRt';

    public function testTheSameAddressAlwaysDrawsTheSameSyllable(): void
    {
        self::assertSame(
            Alias::for(Alias::SITE, self::SITE),
            Alias::for(Alias::SITE, self::SITE),
        );
    }

    public function testTheSyllableFollowsTheAddressAndNotTheRole(): void
    {
        $asSite = Alias::for(Alias::SITE, self::SITE);
        $asPayer = Alias::for(Alias::PAYER, self::SITE);

        self::assertSame(
            substr($asSite, strlen(Alias::SITE)),
            substr($asPayer, strlen(Alias::PAYER)),
            'one address, one syllable, whatever role it is playing',
        );
    }

    public function testDifferentAddressesUsuallyDiffer(): void
    {
        self::assertNotSame(
            Alias::for(Alias::PAYER, self::SITE),
            Alias::for(Alias::PAYER, self::PAYER),
        );
    }

    /**
     * Every prefix means something, and the meaning is what the panel writes
     * under the address now that the table explaining them is gone.
     *
     * The failure this is for is silent: a prefix added without a meaning
     * makes `Alias::meaning()` read a key that is not there, which PHP reports
     * as a warning on a line nobody is watching and then renders as an empty
     * opening to a sentence.
     */
    public function testEveryPrefixHasAMeaning(): void
    {
        foreach (Alias::PREFIXES as $prefix) {
            self::assertNotSame('', Alias::meaning($prefix), $prefix);
        }

        // And the list has to be the list. `PREFIXES` is what the loop above
        // walks and what the panel's test walks, so a twelfth prefix declared
        // and left out of it would be covered by neither — which is the same
        // silence one level up.
        $declared = array_values(array_filter(
            (new \ReflectionClass(Alias::class))->getConstants(),
            static fn (mixed $value): bool => is_string($value),
        ));

        self::assertSame($declared, Alias::PREFIXES, 'PREFIXES is not the prefixes this class declares');
    }

    public function testItIsAPrefixAndAThreeLetterSyllable(): void
    {
        $alias = Alias::for(Alias::CONTRACT, self::PAYER);

        self::assertSame(1, preg_match('/^CPDA[a-z]{3}$/', $alias), $alias);
    }
}
