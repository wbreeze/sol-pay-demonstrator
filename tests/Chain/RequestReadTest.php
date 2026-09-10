<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\AssociatedToken;
use Newsprint\Chain\PayerReader;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\SiteReader;
use Newsprint\Support\Config;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Pda;

/**
 * The claim under the merge, stated so it can fail.
 *
 * SPEC §12.4 asks for the `Site`, the `Contract` and the payer's token account
 * "in one round trip". The code took two, and the reason it took two was
 * `PayerReader::read(string $wallet, SiteState $state)` — a signature that
 * reads as *the payer's addresses are a function of what the site account
 * said*. They are not. The contract PDA is derived from the site **address**,
 * and the associated token account from the **mint**, and setup recorded both
 * of those in `var/site.json` before either was ever read.
 *
 * That is the whole of the argument for batching, so it is the thing tested
 * here: derive the pair the old way, from a `SiteState`, and the new way, from
 * config alone, and require them to be the same strings. If some future change
 * makes a payer address depend on a value only the site *account* carries,
 * this fails and the batch in {@see \Newsprint\Chain\RequestRead} is wrong.
 *
 * The addresses below are this deployment's real devnet accounts, and the
 * wallet is a real reader's, taken from the HAR of 2026-09-10.
 */
final class RequestReadTest extends TestCase
{
    private const SITE = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';
    private const MINT = 'AKRs35WnmePSDLcPLyVtC5UPPBC8xjmVZ4EUJ3XwU9op';
    private const TREASURY = '3qZLoqdTunZJUANtRpaAnpFewAW4UVkgPn6VWzWTk4Mw';
    private const WALLET = 'G4jQTrS8unMpXunB2mkfX7LffPSPbRXsySTQvvfVUoah';

    private string $root = '';

    protected function setUp(): void
    {
        // A provisioned copy, without writing into the working tree: the real
        // `config/site.php`, and a `var/site.json` this test owns.
        $this->root = sys_get_temp_dir().'/newsprint-requestread-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/config', 0o777, true);
        mkdir($this->root.'/var', 0o777, true);
        copy(dirname(__DIR__, 2).'/config/site.php', $this->root.'/config/site.php');
        file_put_contents($this->root.'/var/site.json', json_encode([
            'site' => self::SITE,
            'mint' => self::MINT,
            'treasury' => self::TREASURY,
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/config/site.php');
        @unlink($this->root.'/var/site.json');
        @rmdir($this->root.'/config');
        @rmdir($this->root.'/var');
        @rmdir($this->root);
    }

    public function testThePayersAddressesDoNotDependOnTheSiteAccount(): void
    {
        $config = Config::load($this->root);

        // What `read()` derived while it took a SiteState: the site address
        // carried on that object.
        ['address' => $fromSiteState] = Pda::contractAddress(
            self::SITE,
            self::WALLET,
            $config->program()->id,
        );

        // What `addresses()` derives with no read behind it at all.
        [$fromConfig] = $this->payerReader($config)->addresses(self::WALLET);

        self::assertSame($fromSiteState, $fromConfig);
    }

    /**
     * And the order the two readers' slices go in, because
     * {@see \Newsprint\Chain\RequestRead} splits the response by counting.
     */
    public function testTheBatchIsTheSitesThreeThenThePayersTwo(): void
    {
        $config = Config::load($this->root);

        $site = $this->siteReader($config)->addresses();
        $payer = $this->payerReader($config)->addresses(self::WALLET);

        self::assertSame([self::SITE, self::TREASURY, self::MINT], $site);
        self::assertSame(
            [
                Pda::contractAddress(self::SITE, self::WALLET, $config->program()->id)["address"],
                AssociatedToken::address(self::WALLET, self::MINT, $config->program()->tokenProgram),
            ],
            $payer,
        );
        self::assertCount(5, [...$site, ...$payer]);
    }

    /**
     * A site account that is not there is not a failed read.
     *
     * An unprovisioned copy and a `var/site.json` carried over from another
     * cluster both land here, and neither is an error the panel should report
     * as one — there is simply nothing to decode a contract against.
     */
    public function testAnAbsentSiteAccountDecodesToNothingRatherThanThrowing(): void
    {
        self::assertNull($this->siteReader(Config::load($this->root))->decode([null, null, null]));
    }

    private function siteReader(Config $config): SiteReader
    {
        return new SiteReader($config, $this->rpc($config));
    }

    private function payerReader(Config $config): PayerReader
    {
        return new PayerReader($config, $this->rpc($config));
    }

    /** Constructed, never called: address derivation touches no endpoint. */
    private function rpc(Config $config): Rpc
    {
        return new Rpc($config->rpcUrl(), $config->program(), 'confirmed', 1);
    }
}
