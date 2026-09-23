<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\PayerState;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Contract;
use SolPay\Core\Site;
use SolPay\Core\TokenAccount;

/**
 * Whose delegate is on the reader's token account.
 *
 * The library's `Shortfall::diagnose` answers `delegate !== null`, and a token
 * account holds exactly one delegate, so another site's `approve` satisfies
 * that check while leaving this site unable to settle. The site asks the
 * narrower question, and this is where it asks it.
 */
final class PayerStateTest extends TestCase
{
    private const CONTRACT = 'CtRc11111111111111111111111111111111111111';
    private const OTHER = 'D5VoE7hnS4yDaaMcEfgwcPyMnwNG5Xb2j4WFFFtHKwKq';
    private const PAYER = 'PayR11111111111111111111111111111111111111';
    private const MINT = 'MintI1111111111111111111111111111111111111';

    public function testThisSitesContractIsTheDelegate(): void
    {
        self::assertTrue($this->payer(self::CONTRACT)->delegateIsContract());
    }

    /**
     * The repair. Before it, this read as a delegate in good standing, the
     * meter strip never said otherwise, and the reader learned about it from a
     * refusal.
     */
    public function testAnotherSitesDelegateIsNotThisSites(): void
    {
        $payer = $this->payer(self::OTHER);

        self::assertFalse($payer->delegateIsContract());
        self::assertNotNull($payer->funds?->delegate, 'present, which is exactly why `delegate !== null` cannot decide this');
    }

    public function testAnEmptyDelegateFieldIsNotThisSites(): void
    {
        self::assertFalse($this->payer(null)->delegateIsContract());
    }

    public function testNoTokenAccountIsNotThisSites(): void
    {
        self::assertFalse($this->payer(null, funded: false)->delegateIsContract());
    }

    private function payer(?string $delegate, bool $funded = true): PayerState
    {
        return new PayerState(
            wallet: self::PAYER,
            contractAddress: self::CONTRACT,
            tokenAccount: 'AtA111111111111111111111111111111111111111',
            contract: new Contract(
                site: '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt',
                payer: self::PAYER,
                limit: 500_000,
                used: 230_000,
                paid: 150_000,
                bump: 253,
            ),
            funds: $funded ? new TokenAccount(self::MINT, self::PAYER, 400_000, $delegate, 400_000) : null,
            site: new Site(
                authority: '163aJWGmry7Q2gWjtxmTbdC7NGFc7FecSN1gfpNUgRt',
                mint: self::MINT,
                treasury: 'TrSy11111111111111111111111111111111111111',
                pagePrice: 10_000,
                collectionThreshold: 100_000,
                minLimit: 500_000,
                bump: 254,
            ),
            decimals: 6,
        );
    }
}
