<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Auth\Binding;
use Newsprint\Chain\MeterReader;
use Newsprint\Chain\MeterState;
use Newsprint\Chain\RequestRead;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\SiteReader;
use Newsprint\Support\Config;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Meter;
use SolPay\Core\Pda;
use SolPay\Core\Site;

/**
 * Two claims about one request's read of the chain, each stated so it can
 * fail.
 *
 * **The batch.** SPEC §6.2's diagram reads the site, the treasury, the mint,
 * the meter and the fund's token account in one `getMultipleAccounts`. That is
 * possible only because neither of the meter's two addresses depends on what
 * the *site account* says: the meter's address comes from the session, and
 * the token account is derived from the session's fund and the mint that
 * setup recorded. If a future change made one of them wait on the site read,
 * the batch in {@see RequestRead} would be wrong, and the first two tests say
 * so.
 *
 * **The session check** (SPEC §5.3). A meter that names another browser's
 * key, because another device renewed it, ends this browser's session at the
 * read that finds it. The rest of the tests hold that, through
 * `RequestRead::adopt()`, which is the path the metering lock's read takes
 * back into the request, and which needs no endpoint.
 *
 * The site addresses are this deployment's real devnet accounts.
 */
final class RequestReadTest extends TestCase
{
    private const SITE = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';
    private const MINT = 'AKRs35WnmePSDLcPLyVtC5UPPBC8xjmVZ4EUJ3XwU9op';
    private const TREASURY = '3qZLoqdTunZJUANtRpaAnpFewAW4UVkgPn6VWzWTk4Mw';
    private const READER = 'G4jQTrS8unMpXunB2mkfX7LffPSPbRXsySTQvvfVUoah';
    private const KEY = 'BKeYfig1111111111111111111111111111111111111';
    private const OTHER_KEY = 'BKeYcat1111111111111111111111111111111111111';

    private string $root = '';

    protected function setUp(): void
    {
        // A provisioned copy, without writing into the working tree: the real
        // `config/site.php`, and a `var/site.json` this test owns.
        $this->root = sys_get_temp_dir().'/newsprint-requestread-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/config', 0o777, true);
        mkdir($this->root.'/var', 0o777, true);
        copy(dirname(__DIR__, 2).'/config/site.php', $this->root.'/config/site.php');
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/config/site.php');
        @unlink($this->root.'/var/site.json');
        @rmdir($this->root.'/config');
        @rmdir($this->root.'/var');
        @rmdir($this->root);
    }

    public function testTheMetersAddressesComeFromTheSessionAndSetupAlone(): void
    {
        $config = $this->provisioned();
        $binding = $this->binding();

        [$meter, $tokenAccount, $fund] = $this->meterReader($config)->addresses($binding);

        self::assertSame($binding->meter, $meter, 'the session names the meter');
        self::assertSame(
            Pda::fundTokenAccount($binding->fund, self::MINT, $config->program()->tokenProgram),
            $tokenAccount,
            'the token account is the fund\'s, in the mint setup recorded',
        );
        self::assertSame($binding->fund, $fund, 'and the fund itself, which the inspector decodes');
    }

    /**
     * And the order the two readers' slices go in, because
     * {@see RequestRead} splits the response by counting.
     */
    public function testTheBatchIsTheSitesThreeThenTheMetersThree(): void
    {
        $config = $this->provisioned();

        $site = $this->siteReader($config)->addresses();
        $meter = $this->meterReader($config)->addresses($this->binding());

        self::assertSame([self::SITE, self::TREASURY, self::MINT], $site);
        self::assertCount(6, [...$site, ...$meter], 'one call carries all six');
    }

    /**
     * A site account that is not there is not a failed read.
     *
     * An unprovisioned copy and a `var/site.json` carried over from another
     * cluster both land here, and neither is an error the panel should report
     * as one — there is simply nothing to decode a meter against.
     */
    public function testAnAbsentSiteAccountDecodesToNothingRatherThanThrowing(): void
    {
        self::assertNull($this->siteReader($this->provisioned())->decode([null, null, null]));
    }

    public function testAMeterThatNamesTheSessionsKeyKeepsTheSession(): void
    {
        $ended = [];
        $reads = $this->reads($ended);

        $reads->adopt($this->state(self::KEY));

        self::assertSame([], $ended);
        self::assertFalse($reads->ended());
        self::assertNotNull($reads->binding());
        self::assertNotNull($reads->meter());
    }

    /**
     * SPEC §5.3: renewed from another device, which named its own key. This
     * device's session ends at the read that finds it, with no message
     * between the two devices.
     */
    public function testAMeterNamingAnotherKeyEndsTheSession(): void
    {
        $ended = [];
        $reads = $this->reads($ended);

        $reads->adopt($this->state(self::OTHER_KEY));

        self::assertCount(1, $ended, 'the session is ended once');
        self::assertSame(self::KEY, $ended[0]->key, 'and it is the session under the old key that ends');
        self::assertTrue($reads->ended());
        self::assertNull($reads->binding(), 'nothing on this request acts for that session any more');
        self::assertNull($reads->meter(), 'or shows its meter');
    }

    public function testAMeterThatIsGoneEndsTheSession(): void
    {
        $ended = [];
        $reads = $this->reads($ended);

        $reads->adopt($this->state(null));

        self::assertCount(1, $ended);
        self::assertNull($reads->binding());
    }

    /**
     * A meter that answers to the key but draws on another fund is not the
     * meter this session was bound to. Unreachable through the program, which
     * derives a meter's address from its fund, so this pins the comparison
     * rather than a case the chain produces.
     */
    public function testAMeterOnAnotherFundEndsTheSession(): void
    {
        $ended = [];
        $reads = $this->reads($ended);

        $reads->adopt($this->state(self::KEY, fund: self::TREASURY));

        self::assertCount(1, $ended);
    }

    private function provisioned(): Config
    {
        file_put_contents($this->root.'/var/site.json', json_encode([
            'site' => self::SITE,
            'mint' => self::MINT,
            'treasury' => self::TREASURY,
        ]));

        return Config::load($this->root);
    }

    /**
     * Unprovisioned on purpose: `adopt()` runs the request's own read first,
     * and an unprovisioned copy reads nothing, so no endpoint is touched.
     *
     * @param list<Binding> $ended collects every binding the read ends
     */
    private function reads(array &$ended): RequestRead
    {
        $config = Config::load($this->root);
        self::assertFalse($config->isProvisioned(), 'this fixture must not reach an endpoint');

        return new RequestRead($config, $this->rpc($config), $this->binding(), static function (Binding $binding) use (&$ended): void {
            $ended[] = $binding;
        });
    }

    private function binding(): Binding
    {
        $fund = Pda::fundAddress(self::READER, self::MINT, 0)['address'];

        return new Binding(Pda::meterAddress(self::SITE, $fund)['address'], $fund, self::KEY);
    }

    private function state(?string $key, ?string $fund = null): MeterState
    {
        $binding = $this->binding();
        $site = new Site(self::READER, self::MINT, self::TREASURY, 10_000, 100_000, 500_000, 255);

        return new MeterState(
            meterAddress: $binding->meter,
            fund: $binding->fund,
            fundTokenAccount: Pda::fundTokenAccount($binding->fund, self::MINT),
            meter: $key === null ? null : new Meter(self::SITE, $fund ?? $binding->fund, $key, 1_800_000_000, 500_000, 0, 0, 254),
            funds: null,
            site: $site,
            decimals: 6,
        );
    }

    private function siteReader(Config $config): SiteReader
    {
        return new SiteReader($config, $this->rpc($config));
    }

    private function meterReader(Config $config): MeterReader
    {
        return new MeterReader($config, $this->rpc($config));
    }

    /** Constructed, never called: address derivation touches no endpoint. */
    private function rpc(Config $config): Rpc
    {
        return new Rpc($config->rpcUrl(), $config->program(), 'confirmed', 1);
    }
}
