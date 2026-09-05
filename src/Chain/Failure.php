<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Cause;
use SolPay\Core\Program;

/**
 * **The one place transaction logs are read, and they do not leave it.**
 *
 * SPEC §8.1: logs and account lists carry the payer's wallet address, and the
 * program's events carry `used`, `paid` and `transferred` as decodable base64.
 * None of it is secret and all of it is on chain — but copying it into an
 * application log or an error tracker moves a reader's spending history into
 * systems that were never scoped to hold it. So this class takes the raw error
 * object, returns a `Cause` and a signature, and drops everything else before
 * it returns. Nothing downstream can print what it never received.
 *
 * The reader can read their own logs on the explorer, where they belong.
 */
final class Failure
{
    private function __construct(
        public readonly string $message,
        public readonly ?Cause $cause,
        public readonly ?int $code,
    ) {
    }

    /**
     * Attribute an RPC error. `$error` is the JSON-RPC `error` member as it
     * arrived; it is read here and discarded.
     *
     * @param array<string, mixed> $error
     */
    public static function fromRpcError(array $error, Program $program): self
    {
        $data = is_array($error['data'] ?? null) ? $error['data'] : [];
        $code = self::customCode($data['err'] ?? null);
        $raisedBy = self::raisingProgram(is_array($data['logs'] ?? null) ? $data['logs'] : []);

        // Attribution needs the raising program, and the code alone does not
        // supply it: `LimitReached` is 6003 from the metering program and
        // `InsufficientFunds` is 1 from SPL Token (§8.1). Without logs, say
        // so rather than attributing to the instruction's own program — a
        // failure inside `meter_and_settle`'s `transfer_checked` CPI is
        // reported against the top-level instruction, so that guess names the
        // wrong program precisely when it matters.
        $cause = ($code !== null && $raisedBy !== null)
            ? Cause::of($program, $raisedBy, $code)
            : null;

        return new self(self::scrub((string) ($error['message'] ?? 'transaction failed')), $cause, $code);
    }

    /** A landed transaction that failed, from a status with no logs beside it. */
    public static function fromStatusError(mixed $err): self
    {
        return new self('transaction failed on chain', null, self::customCode($err));
    }

    /** `{"InstructionError": [0, {"Custom": 6003}]}` — the only shape carrying a program's own code. */
    private static function customCode(mixed $err): ?int
    {
        if (!is_array($err) || !isset($err['InstructionError'][1]['Custom'])) {
            return null;
        }
        $code = $err['InstructionError'][1]['Custom'];

        return is_int($code) ? $code : null;
    }

    /**
     * The last program to report a failure is the one that raised it, which
     * is what makes a CPI attributable at all.
     *
     * @param list<mixed> $logs
     */
    private static function raisingProgram(array $logs): ?string
    {
        $raisedBy = null;
        foreach ($logs as $line) {
            if (is_string($line) && preg_match('/^Program (\S+) failed/', $line, $m) === 1) {
                $raisedBy = $m[1];
            }
        }

        return $raisedBy;
    }

    /**
     * RPC error messages sometimes carry the whole log array inline. Keep the
     * first line and drop the rest — the rule above is worth nothing if it is
     * defeated by a message field.
     */
    private static function scrub(string $message): string
    {
        $firstLine = strtok($message, "\n");

        return substr($firstLine === false ? 'transaction failed' : $firstLine, 0, 200);
    }
}
