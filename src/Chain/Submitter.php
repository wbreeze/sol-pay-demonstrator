<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use SolPay\Core\Instruction;
use SolPay\Core\Tx;

/**
 * Instructions in, a landed signature out: fetch a blockhash, compile with
 * `SolPay\Core\Tx`, sign, send, and poll for a bounded window (SPEC §7.3).
 *
 * This is the seam sol-pay deliberately leaves open — SPEC §7 of the library:
 * it never signs, talks to an RPC, or retries. Everything that does is here.
 */
final class Submitter
{
    public function __construct(
        private readonly Rpc $rpc,
        private readonly int $confirmTimeoutMs = 20_000,
        private readonly int $pollMs = 500,
    ) {
    }

    /**
     * @param Instruction[] $instructions in the order the program requires
     * @param Keypair[]     $extraSigners any signer besides the fee payer — a
     *                                    new account being created, say
     */
    public function send(array $instructions, Keypair $feePayer, array $extraSigners = []): Outcome
    {
        $blockhash = $this->rpc->latestBlockhash()['blockhash'];
        $message = Tx::compile($instructions, $feePayer->address, $blockhash);

        $signers = [$feePayer->address => $feePayer];
        foreach ($extraSigners as $signer) {
            $signers[$signer->address] = $signer;
        }

        $wire = Tx::wire($message, MessageSigner::signatures($message, $signers));

        try {
            $signature = $this->rpc->sendTransaction($wire);
        } catch (RpcException $e) {
            return Outcome::failed(null, $e->failure);
        }

        return $this->confirm($signature);
    }

    /**
     * Poll until the cluster answers or the window closes. An `err` here is a
     * transaction that landed and failed; attributing it needs the logs
     * `getSignatureStatuses` does not return, so the cause is left unnamed
     * rather than guessed at — see {@see Failure}. The metering path will want
     * `getTransaction` for that.
     */
    private function confirm(string $signature): Outcome
    {
        $deadline = microtime(true) + $this->confirmTimeoutMs / 1000;

        do {
            $status = $this->rpc->signatureStatuses([$signature])[0] ?? null;
            if ($status !== null) {
                if ($status['err'] !== null) {
                    return Outcome::failed($signature, Failure::fromStatusError($status['err']));
                }
                if (in_array($status['confirmationStatus'], ['confirmed', 'finalized'], true)) {
                    return Outcome::confirmed($signature);
                }
            }
            usleep($this->pollMs * 1000);
        } while (microtime(true) < $deadline);

        return Outcome::unconfirmed($signature);
    }
}
