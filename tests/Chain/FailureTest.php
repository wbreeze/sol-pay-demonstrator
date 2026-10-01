<?php

declare(strict_types=1);

namespace Newsprint\Tests\Chain;

use Newsprint\Chain\Failure;
use PHPUnit\Framework\TestCase;
use SolPay\Core\CauseKind;
use SolPay\Core\Ids;
use SolPay\Core\PayError;
use SolPay\Core\Program;
use SolPay\Core\TokenError;

/**
 * Which program raised a failure, read from the logs (SPEC §8.1).
 *
 * The runtime writes a `Program <id> failed: …` line for every frame the
 * error passes through on its way out. A failure inside a cross-program
 * invocation therefore leaves two lines or more: the callee's first, then
 * each caller's, all carrying the callee's code. The raiser is the first.
 *
 * Found on devnet on 2026-10-01 by `bin/fund-trials wrong`: `open_fund` on
 * an index in use fails when Anchor's `init` asks the System program to
 * create an account that exists. The System program raised code 0, and the
 * site reported code 0 from the metering program, which reads as a metering
 * program error that does not exist. The same reading would have reported a
 * settle on a short fund — SPL Token's `InsufficientFunds` inside
 * `meter_and_settle`'s transfer — as an unknown code 1 from the metering
 * program, which is SPEC §8.2's most important row.
 *
 * The log lines below are the runtime's format, reduced to what this class
 * reads. The addresses in them are fixed values, not anybody's.
 */
final class FailureTest extends TestCase
{
    private const FUND = 'G6t9ZzucAB7RrM5CLtyEDao1HDV3aZFQXR2VmUXTeGbk';

    public function testAProgramsOwnErrorIsItsOwn(): void
    {
        $failure = $this->failure(6003, [
            'Program '.Ids::PAY_ON_CHAIN_ID.' invoke [1]',
            'Program log: Instruction: MeterAndSettle',
            'Program log: AnchorError occurred. Error Code: LimitReached. Error Number: 6003.',
            'Program '.Ids::PAY_ON_CHAIN_ID.' consumed 5000 of 200000 compute units',
            'Program '.Ids::PAY_ON_CHAIN_ID.' failed: custom program error: 0x1773',
        ]);

        self::assertSame(CauseKind::Program, $failure->cause?->kind);
        self::assertSame(PayError::LimitReached, $failure->cause->payError);
    }

    public function testATopLevelTokenInstructionIsTheTokenPrograms(): void
    {
        // The deposit, which is a plain `transfer_checked` and no CPI.
        $failure = $this->failure(1, [
            'Program '.Ids::TOKEN_PROGRAM_ID.' invoke [1]',
            'Program log: Instruction: TransferChecked',
            'Program log: Error: insufficient funds',
            'Program '.Ids::TOKEN_PROGRAM_ID.' consumed 3000 of 200000 compute units',
            'Program '.Ids::TOKEN_PROGRAM_ID.' failed: custom program error: 0x1',
        ]);

        self::assertSame(CauseKind::Token, $failure->cause?->kind);
        self::assertSame(TokenError::InsufficientFunds, $failure->cause->tokenError);
    }

    /** The repair. A short fund at settle time is the callee's error, not the caller's. */
    public function testATokenFailureInsideTheSettleIsTheTokenPrograms(): void
    {
        $failure = $this->failure(1, [
            'Program '.Ids::PAY_ON_CHAIN_ID.' invoke [1]',
            'Program log: Instruction: MeterAndSettle',
            'Program '.Ids::TOKEN_PROGRAM_ID.' invoke [2]',
            'Program log: Instruction: TransferChecked',
            'Program log: Error: insufficient funds',
            'Program '.Ids::TOKEN_PROGRAM_ID.' consumed 3000 of 190000 compute units',
            'Program '.Ids::TOKEN_PROGRAM_ID.' failed: custom program error: 0x1',
            'Program '.Ids::PAY_ON_CHAIN_ID.' consumed 12000 of 200000 compute units',
            'Program '.Ids::PAY_ON_CHAIN_ID.' failed: custom program error: 0x1',
        ]);

        self::assertSame(CauseKind::Token, $failure->cause?->kind);
        self::assertSame(TokenError::InsufficientFunds, $failure->cause->tokenError);
    }

    /** The case devnet showed: `init` on an account in use, raised by the System program. */
    public function testASystemFailureInsideInitIsTheSystemPrograms(): void
    {
        $failure = $this->failure(0, [
            'Program '.Ids::PAY_ON_CHAIN_ID.' invoke [1]',
            'Program log: Instruction: OpenFund',
            'Program '.Ids::SYSTEM_PROGRAM_ID.' invoke [2]',
            'Allocate: account Address { address: '.self::FUND.', base: None } already in use',
            'Program '.Ids::SYSTEM_PROGRAM_ID.' failed: custom program error: 0x0',
            'Program '.Ids::PAY_ON_CHAIN_ID.' consumed 6000 of 200000 compute units',
            'Program '.Ids::PAY_ON_CHAIN_ID.' failed: custom program error: 0x0',
        ]);

        self::assertSame(CauseKind::Unknown, $failure->cause?->kind);
        self::assertSame(Ids::SYSTEM_PROGRAM_ID, $failure->cause->unknownProgram);
        self::assertSame(0, $failure->cause->unknownCode);
    }

    /** @param list<string> $logs */
    private function failure(int $code, array $logs): Failure
    {
        return Failure::fromRpcError([
            'code' => -32002,
            'message' => 'Transaction simulation failed: Error processing Instruction 0: custom program error: 0x'.dechex($code),
            'data' => [
                'err' => ['InstructionError' => [0, ['Custom' => $code]]],
                'logs' => $logs,
            ],
        ], Program::default());
    }
}
