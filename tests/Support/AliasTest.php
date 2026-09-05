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

    public function testItIsAPrefixAndAThreeLetterSyllable(): void
    {
        $alias = Alias::for(Alias::CONTRACT, self::PAYER);

        self::assertSame(1, preg_match('/^CPDA[a-z]{3}$/', $alias), $alias);
    }
}
