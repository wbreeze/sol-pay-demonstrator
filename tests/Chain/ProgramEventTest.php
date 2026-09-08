<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\ProgramEvent;
use PHPUnit\Framework\TestCase;
use SolPay\Core\Base58;

/**
 * The decoder for SPEC §9's last section.
 *
 * Two kinds of check, and the first one is the one that will actually catch
 * something. The discriminators are constants of the program's *build* —
 * `sha256("event:<Name>")[..8]`, a convention of Anchor and not of the runtime
 * — so a rename upstream changes them with no error anywhere. The first test
 * derives them here rather than trusting the copy in the class, which is the
 * cheapest place to notice that the two have parted company.
 *
 * The rest are the refusals. Every one is checked in both directions: the same
 * bytes decode when they are right and return null when one thing about them
 * is wrong, because a decoder that only ever sees well-formed input is a
 * decoder nobody has tested.
 */
final class ProgramEventTest extends TestCase
{
    private const CONTRACT = 'CPDA';

    /** 32 bytes that stand in for a contract PDA. */
    private function contractBytes(): string
    {
        return str_pad(self::CONTRACT, 32, "\x11");
    }

    private function metered(int $pageViews = 7, int $used = 70_000, int $paid = 100_000, int $transferred = 100_000): string
    {
        return hex2bin('1e8e96a17c2e1d7e')
            .$this->contractBytes()
            .pack('V', $pageViews)
            .pack('P', $used)
            .pack('P', $paid)
            .pack('P', $transferred);
    }

    public function testTheDiscriminatorsAreTheOnesAnchorWouldWrite(): void
    {
        foreach (ProgramEvent::known() as $name) {
            self::assertSame(
                bin2hex(substr(hash('sha256', 'event:'.$name, true), 0, 8)),
                ProgramEvent::discriminatorFor($name),
                "the discriminator for {$name} is not sha256(\"event:{$name}\")[..8] — the program renamed it, or this copy drifted",
            );
        }
    }

    public function testKnowsTheThreeEventsAndNothingElse(): void
    {
        self::assertSame(['Metered', 'Renewed', 'Closed'], ProgramEvent::known());
        self::assertNull(ProgramEvent::discriminatorFor('Opened'));
    }

    public function testDecodesMeteredFieldByFieldInDeclarationOrder(): void
    {
        $event = ProgramEvent::decode($this->metered());

        self::assertNotNull($event);
        self::assertSame('Metered', $event->name);
        self::assertSame(Base58::encode($this->contractBytes()), $event->contract);
        self::assertSame(
            ['contract', 'page_views', 'used', 'paid', 'transferred'],
            array_keys($event->fields),
            'the field order is the program\'s declaration order and borsh depends on it',
        );
        self::assertSame(7, $event->fields['page_views']);
        self::assertSame(70_000, $event->fields['used']);
        self::assertSame(100_000, $event->fields['paid']);
        self::assertSame(100_000, $event->fields['transferred']);
    }

    public function testDecodesRenewed(): void
    {
        $bytes = hex2bin('8bfcd923492a0757').$this->contractBytes().pack('P', 500_000).pack('P', 40_000);
        $event = ProgramEvent::decode($bytes);

        self::assertNotNull($event);
        self::assertSame('Renewed', $event->name);
        self::assertSame(['contract', 'limit', 'carried'], array_keys($event->fields));
        self::assertSame(500_000, $event->fields['limit']);
        self::assertSame(40_000, $event->fields['carried']);
    }

    public function testDecodesClosed(): void
    {
        $bytes = hex2bin('321f579b87dcc3ef').$this->contractBytes().pack('P', 30_000);
        $event = ProgramEvent::decode($bytes);

        self::assertNotNull($event);
        self::assertSame('Closed', $event->name);
        self::assertSame(['contract', 'forgiven'], array_keys($event->fields));
        self::assertSame(30_000, $event->fields['forgiven']);
    }

    /**
     * The check that earns its place. Another program's `Program data:` line is
     * the realistic case — a metering transaction carries a CPI into SPL Token
     * — and without the discriminator test those bytes would be read as ours
     * and produce numbers that look like an answer.
     */
    public function testRefusesAnotherProgramsData(): void
    {
        $foreign = hex2bin('deadbeefdeadbeef').$this->contractBytes().pack('V', 7).str_repeat("\x00", 24);

        self::assertNull(ProgramEvent::decode($foreign));
    }

    public function testRefusesATruncatedBody(): void
    {
        self::assertNotNull(ProgramEvent::decode($this->metered()), 'the full body decodes');
        self::assertNull(ProgramEvent::decode(substr($this->metered(), 0, -1)), 'one byte short does not');
    }

    public function testRefusesABodyLongerThanTheLayout(): void
    {
        self::assertNull(ProgramEvent::decode($this->metered()."\x00"));
    }

    public function testRefusesADiscriminatorWithNoBody(): void
    {
        self::assertNull(ProgramEvent::decode(hex2bin('1e8e96a17c2e1d7e')));
        self::assertNull(ProgramEvent::decode(''));
    }

    /**
     * PHP has no unsigned 64-bit integer, so a u64 past PHP_INT_MAX comes back
     * from `unpack('P')` negative. Reporting it as unreadable is the only
     * honest option: the alternative is a number with the sign flipped, in the
     * one panel whose purpose is to be checkable. Unreachable with real token
     * amounts, which is exactly why nothing else would ever catch it.
     */
    public function testRefusesAValuePastWhatPhpCanHold(): void
    {
        $tooBig = hex2bin('321f579b87dcc3ef').$this->contractBytes()."\xff\xff\xff\xff\xff\xff\xff\xff";

        self::assertNull(ProgramEvent::decode($tooBig));
    }

    public function testFindsTheEventAmongOrdinaryLogLines(): void
    {
        $logs = [
            'Program F8UDAGgxVTm8Vmh4RmskpMBCFqhRvuTqbDxDCj8UMedL invoke [1]',
            'Program log: Instruction: MeterAndSettle',
            'Program TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA invoke [2]',
            'Program data: '.base64_encode(hex2bin('deadbeefdeadbeef').'not ours'),
            'Program data: '.base64_encode($this->metered()),
            'Program F8UDAGgxVTm8Vmh4RmskpMBCFqhRvuTqbDxDCj8UMedL success',
        ];

        $event = ProgramEvent::fromLogs($logs);

        self::assertNotNull($event);
        self::assertSame('Metered', $event->name);
        self::assertSame(7, $event->fields['page_views']);
    }

    public function testFindsNothingWhenThereIsNoEvent(): void
    {
        self::assertNull(ProgramEvent::fromLogs([
            'Program log: Instruction: MeterAndSettle',
            'Program log: Program data: this is a log line about a data line',
            'Program data: not base64 at all !!!!',
        ]));
        self::assertNull(ProgramEvent::fromLogs([]));
    }
}
