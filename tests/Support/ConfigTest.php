<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * Where the provisioned half of the configuration lives (SPEC §12.6).
 *
 * `bin/setup --into` makes the hosted instance's keys on the development
 * machine. That is only safe if a named directory is the *whole* of where a
 * `Config` reads and writes: a key path that still pointed at `var/` would
 * provision the hosted site with the local site's authority.
 */
final class ConfigTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/newsprint-config-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) {
            unlink($path);
        }
        is_dir($this->dir) && rmdir($this->dir);
    }

    public function testANamedDirectoryHoldsEverythingSetupWrites(): void
    {
        $config = Config::load(self::ROOT, $this->dir.'/');

        self::assertSame($this->dir, $config->varDir());
        self::assertSame($this->dir.'/authority.json', $config->keypairPath('authority'));
        self::assertSame($this->dir.'/newsprint.sqlite', $config->dbPath());
        self::assertFalse($config->isProvisioned());

        $config->writeProvisioned(['site' => 'SITEfig']);

        self::assertFileExists($this->dir.'/site.json');
        self::assertTrue(Config::load(self::ROOT, $this->dir)->isProvisioned());
    }

    /**
     * The hosted instance's address is a fact about one machine, so it
     * arrives in `host.json` beside the keys and not in the tracked file.
     */
    public function testTheHostedInstanceLearnsItsAddressFromBesideItsKeys(): void
    {
        self::assertNull(Config::load(self::ROOT, $this->dir)->publicUrl());
        self::assertFalse(Config::load(self::ROOT, $this->dir)->isHosted());

        mkdir($this->dir, 0o700);
        file_put_contents($this->dir.'/host.json', '{"public_url":"https://newsprint.test/"}');

        $hosted = Config::load(self::ROOT, $this->dir);
        self::assertSame('https://newsprint.test', $hosted->publicUrl());
        self::assertTrue($hosted->isHosted());
    }

    /**
     * A stack trace names the directories on the machine that printed it. The
     * hosted instance answers strangers, so its error pages leave the details
     * out. Textual, like {@see SafeMethodTest}: the front controller reads its
     * configuration from one fixed place, so no test can boot it as hosted.
     */
    public function testTheHostedInstanceDoesNotPrintErrorDetails(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/public/index.php');

        self::assertSame(1, substr_count($source, 'addErrorMiddleware('));
        self::assertStringContainsString('addErrorMiddleware(!$config->isHosted(), true, true)', $source);
    }
}
