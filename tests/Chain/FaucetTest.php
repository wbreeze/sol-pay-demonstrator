<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\Faucet;
use Newsprint\Chain\Keypair;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\Submitter;
use Newsprint\Store\Database;
use Newsprint\Store\Store;
use Newsprint\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * The faucet's refusals that need no chain (SPEC §4.3): a pasted string that
 * is not an address, an address that has had its grant, and a source that has
 * asked as often as it may. Each is decided before anything is sent, so the
 * endpoint these are built against is one that would refuse a connection, and
 * a test that reached it would fail by saying so.
 */
final class FaucetTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/newsprint-faucet-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/config', 0o700, true);
        mkdir($this->root.'/var', 0o700, true);
        $site = (string) file_get_contents(dirname(__DIR__, 2).'/config/site.php');
        // Port 9 is discard: nothing answers, so a send would fail loudly.
        file_put_contents($this->root.'/config/site.php', str_replace('https://api.devnet.solana.com', 'http://127.0.0.1:9', $site));
        file_put_contents($this->root.'/var/site.json', json_encode([
            'site' => Keypair::generate()->address,
            'mint' => Keypair::generate()->address,
            'treasury' => Keypair::generate()->address,
        ]));
        Keypair::generate()->save($this->root.'/var/faucet.json');
    }

    protected function tearDown(): void
    {
        foreach (['/config/site.php', '/var/site.json', '/var/faucet.json'] as $file) {
            @unlink($this->root.$file);
        }
        @rmdir($this->root.'/config');
        @rmdir($this->root.'/var');
        @rmdir($this->root);
    }

    /** @return array{0: Faucet, 1: Store} */
    private function faucet(): array
    {
        $config = Config::load($this->root);
        $rpc = new Rpc($config->rpcUrl(), $config->program(), 'confirmed', 1);
        $store = new Store(Database::open(':memory:'));

        return [new Faucet($config, Submitter::fromConfig($rpc, $config), $store), $store];
    }

    public function testWhatIsPastedMustBeAnAddress(): void
    {
        [$faucet] = $this->faucet();

        foreach (['', 'not an address', '0x52908400098527886E0F7030069857D2E4169EE7', str_repeat('1', 20), str_repeat('z', 44)] as $pasted) {
            $result = $faucet->grant($pasted, '203.0.113.7');
            self::assertFalse($result['granted'], $pasted);
            self::assertStringContainsString('not a wallet address', $result['message'], $pasted);
        }

        self::assertTrue(Faucet::isAddress(Keypair::generate()->address));
        self::assertTrue(Faucet::isAddress('11111111111111111111111111111111'), 'thirty-two zero bytes are an address');
    }

    /** §13.2: the faucet refuses the same address a second grant, and says so. */
    public function testAnAddressThatHasHadItsGrantIsRefusedBeforeAnythingIsCounted(): void
    {
        [$faucet, $store] = $this->faucet();
        $address = Keypair::generate()->address;
        $store->recordFaucet($address, 'SIGfig');

        for ($i = 0; $i < 8; $i++) {
            $result = $faucet->grant($address, '203.0.113.7');
            self::assertFalse($result['granted']);
            self::assertSame('this wallet has already had its one grant', $result['message']);
        }
    }

    /**
     * The limit is per source and counts sends that fail, so a source cannot
     * hammer the endpoint through this form. Five attempts reach the send,
     * which fails here because nothing answers; the sixth is refused before
     * it. Another source is not affected, and the store never holds the IP
     * address itself.
     */
    public function testASourceIsLimitedAndTheStoreNeverHoldsItsAddress(): void
    {
        [$faucet, $store] = $this->faucet();

        for ($i = 0; $i < 5; $i++) {
            $result = $faucet->grant(Keypair::generate()->address, '203.0.113.7');
            self::assertFalse($result['granted']);
            self::assertStringNotContainsString('network address', $result['message'], "attempt {$i} reached the send");
        }

        $sixth = $faucet->grant(Keypair::generate()->address, '203.0.113.7');
        self::assertStringContainsString('requests from your network address', $sixth['message']);

        $other = $faucet->grant(Keypair::generate()->address, '198.51.100.9');
        self::assertStringNotContainsString('network address', $other['message']);

        $rows = (new \ReflectionProperty(Store::class, 'pdo'))->getValue($store)
            ->query('SELECT source FROM faucet_sources')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(6, $rows);
        foreach ($rows as $source) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $source);
            self::assertStringNotContainsString('203.0.113.7', $source);
        }
    }
}
