<?php

declare(strict_types=1);

namespace Newsprint\Tests\Setup;

use Newsprint\Setup\KeyRoll;
use Newsprint\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * The half of a roll that touches no chain: the old site's files are set
 * aside whole, and the directory is left unprovisioned for setup to fill.
 *
 * Moving the SOL is a transaction and is not tested here. `bin/setup --roll`
 * prints what it sent.
 */
final class KeyRollTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/newsprint-roll-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,*/}*', GLOB_BRACE) ?: [] as $path) {
            is_file($path) && unlink($path);
        }
        foreach (glob($this->dir.'/*', GLOB_ONLYDIR) ?: [] as $path) {
            rmdir($path);
        }
        rmdir($this->dir);
    }

    public function testTheOldSiteIsSetAsideAndTheDirectoryIsLeftUnprovisioned(): void
    {
        foreach (['authority', 'faucet', 'mint'] as $role) {
            file_put_contents("{$this->dir}/{$role}.json", $role);
        }
        file_put_contents($this->dir.'/site.json', '{"site":"SITEfig"}');
        // The store and the host's address are not the site's keys. A roll on
        // the development machine leaves them where they are.
        file_put_contents($this->dir.'/host.json', '{"public_url":"https://newsprint.test"}');

        $aside = KeyRoll::setAside(Config::load(self::ROOT, $this->dir), '20261005-120000');

        self::assertSame($this->dir.'/rolled-20261005-120000', $aside);
        foreach (['authority', 'faucet', 'mint'] as $role) {
            self::assertFileDoesNotExist("{$this->dir}/{$role}.json");
            self::assertSame($role, file_get_contents("{$aside}/{$role}.json"), 'moved, not rewritten');
        }
        self::assertFileExists($this->dir.'/host.json');

        $after = Config::load(self::ROOT, $this->dir);
        self::assertFalse($after->isProvisioned());
        self::assertSame([], $after->partial(), 'or setup would resume the old mint');
    }

    /** A host holds no mint keypair, and a roll there must not trip on its absence. */
    public function testAMissingMintKeypairIsNotAnError(): void
    {
        file_put_contents($this->dir.'/authority.json', 'authority');
        file_put_contents($this->dir.'/site.json', '{"site":"SITEfig"}');

        $aside = KeyRoll::setAside(Config::load(self::ROOT, $this->dir), 'stamp');

        self::assertFileExists($aside.'/authority.json');
        self::assertFileDoesNotExist($aside.'/mint.json');
    }

    public function testThereIsNothingToRollBeforeSetup(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no provisioned site');

        KeyRoll::setAside(Config::load(self::ROOT, $this->dir), 'stamp');
    }
}
