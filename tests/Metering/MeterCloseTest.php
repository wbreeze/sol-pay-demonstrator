<?php

declare(strict_types=1);

namespace Newsprint\Tests\Metering;

use Newsprint\Chain\Keypair;
use Newsprint\Chain\MessageSigner;
use Newsprint\Metering\MeterClose;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;
use SolPay\Core\Ids;
use SolPay\Core\Ix;
use SolPay\Core\Pda;
use SolPay\Core\Program;

/**
 * SPEC §5.4's message, checked by decoding it: who signs, who pays, which
 * program, which accounts, which instruction. And the assembly, which must
 * refuse a signature that is not the key's.
 */
final class MeterCloseTest extends TestCase
{
    private const SITE = '7X4hDbm44UQYnmXshwSdCyAMhh3bJe2X5u1z2m1dSCVt';
    private const READER = 'G4jQTrS8unMpXunB2mkfX7LffPSPbRXsySTQvvfVUoah';
    private const MINT = 'AKRs35WnmePSDLcPLyVtC5UPPBC8xjmVZ4EUJ3XwU9op';

    private Keypair $authority;
    private Keypair $key;
    private string $fund;
    private string $message;

    protected function setUp(): void
    {
        $this->authority = Keypair::generate();
        $this->key = Keypair::generate();
        $this->fund = Pda::fundAddress(self::READER, self::MINT, 0)['address'];
        $this->message = MeterClose::compose(
            Program::default(),
            self::SITE,
            $this->fund,
            self::READER,
            $this->key->address,
            $this->authority->address,
            Base58::encode(str_repeat("\x07", 32)),
        );
    }

    public function testTheAuthorityPaysAndTheKeySigns(): void
    {
        $keys = MessageSigner::accountKeys($this->message);

        self::assertSame(2, ord($this->message[0]), 'two signatures: the fee payer and the key');
        self::assertSame(1, ord($this->message[1]), 'one readonly signer, the key: it signs and nothing is written to it');
        self::assertSame($this->authority->address, $keys[0], 'the fee payer leads');
        self::assertSame($this->key->address, $keys[1]);
        self::assertNotContains(self::READER, array_slice($keys, 0, 2), 'the reader does not sign; the rent goes back to them');
    }

    /**
     * SPEC §9.2: the panel shows the close's instruction read back out of
     * the kept message. For that to be the builder's output and not an
     * approximation of it, the read has to return what `Ix::closeMeter`
     * built: the program, the accounts in the instruction's order with the
     * builder's flags, and the data.
     */
    public function testTheInstructionReadBackOutOfTheMessageIsTheBuilders(): void
    {
        $built = Ix::closeMeter(Program::default(), $this->key->address, self::READER, self::SITE, $this->fund);

        self::assertEquals([$built], MessageSigner::instructions($this->message));
    }

    public function testItIsOneCloseMeterOnTheMeterThisSiteDerives(): void
    {
        [$program, $accounts, $data] = $this->onlyInstruction();

        self::assertSame(Ids::PAY_ON_CHAIN_ID, $program);
        self::assertSame(
            [$this->key->address, self::SITE, $this->fund, self::READER, Pda::meterAddress(self::SITE, $this->fund)['address']],
            $accounts,
            'signer, site, fund, reader, meter: the order Ix::closeMeter names them',
        );
        self::assertSame(bin2hex(substr(hash('sha256', 'global:close_meter', true), 0, 8)), bin2hex($data), 'the discriminator and no arguments');
    }

    public function testTheKeysSignatureAndTheAuthoritysMakeTheWire(): void
    {
        $signature = $this->key->sign($this->message);
        $wire = MeterClose::assemble($this->message, $this->key->address, $signature, $this->authority);

        self::assertNotNull($wire);
        self::assertSame(2, ord($wire[0]));
        self::assertTrue(sodium_crypto_sign_verify_detached(substr($wire, 1, 64), $this->message, $this->authority->publicKeyBytes()), 'the authority first');
        self::assertSame($signature, substr($wire, 65, 64), 'then the key');
        self::assertSame($this->message, substr($wire, 129));
    }

    public function testASignatureByAnyoneButTheKeyIsRefused(): void
    {
        $stranger = Keypair::generate();

        self::assertNull(MeterClose::assemble($this->message, $this->key->address, $stranger->sign($this->message), $this->authority));
        self::assertNull(MeterClose::assemble($this->message, $this->key->address, str_repeat("\0", 64), $this->authority));
        self::assertNull(MeterClose::assemble($this->message, $this->key->address, 'short', $this->authority));
    }

    /** The key's signature over other bytes is not a signature over this message. */
    public function testASignatureOverAnotherMessageIsRefused(): void
    {
        $other = MeterClose::compose(Program::default(), self::SITE, $this->fund, self::READER, $this->key->address, $this->authority->address, Base58::encode(str_repeat("\x08", 32)));

        self::assertNull(MeterClose::assemble($this->message, $this->key->address, $this->key->sign($other), $this->authority));
    }

    /** @return array{0: string, 1: list<string>, 2: string} program, accounts, data */
    private function onlyInstruction(): array
    {
        $keys = MessageSigner::accountKeys($this->message);
        $at = 3 + 1 + 32 * count($keys) + 32;
        self::assertSame(1, ord($this->message[$at]), 'one instruction');
        $at++;
        $program = $keys[ord($this->message[$at++])];
        $count = ord($this->message[$at++]);
        $accounts = [];
        for ($i = 0; $i < $count; $i++) {
            $accounts[] = $keys[ord($this->message[$at++])];
        }
        $length = ord($this->message[$at++]);

        return [$program, $accounts, substr($this->message, $at, $length)];
    }
}
