<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Support\Config;
use SolPay\Core\Base58;
use SolPay\Core\Instruction;
use SolPay\Core\Tx;

/**
 * Instructions in, a landed signature out: fetch a blockhash, compile with
 * `SolPay\Core\Tx`, sign, send, and ask a bounded number of times whether it
 * landed (SPEC §7.3).
 *
 * This is the seam sol-pay deliberately leaves open — SPEC §7 of the library:
 * it never signs, talks to an RPC, or retries. Everything that does is here.
 */
final class Submitter
{
    public function __construct(
        private readonly Rpc $rpc,
        private readonly int $attempts = 6,
        private readonly int $spacingMs = 2_000,
    ) {
    }

    /** The one place the confirmation schedule is read (`config/site.php`, `rpc`). */
    public static function fromConfig(Rpc $rpc, Config $config): self
    {
        return new self(
            $rpc,
            (int) $config->rpc()['confirm_attempts'],
            (int) $config->rpc()['confirm_spacing_ms'],
        );
    }

    /**
     * @param Instruction[] $instructions in the order the program requires
     * @param Keypair[]     $extraSigners any signer besides the fee payer — a
     *                                    new account being created, say
     * @param bool          $wait         ask whether it landed, or return as soon
     *                                    as the endpoint has accepted it
     *                                    (SPEC §7.3's serve-first charge)
     * @param ChargeFault   $fault        a devnet test fault; see {@see ChargeFault}
     */
    public function send(array $instructions, Keypair $feePayer, array $extraSigners = [], bool $wait = true, ChargeFault $fault = ChargeFault::None): Outcome
    {
        // `never-land` compiles against a blockhash nobody issued: 32 random
        // bytes. The validators discard the transaction, which is the point.
        $blockhash = $fault === ChargeFault::NeverLand
            ? Base58::encode(random_bytes(32))
            : $this->rpc->latestBlockhash()['blockhash'];
        $message = Tx::compile($instructions, $feePayer->address, $blockhash);

        $signers = [$feePayer->address => $feePayer];
        foreach ($extraSigners as $signer) {
            $signers[$signer->address] = $signer;
        }

        $wire = Tx::wire($message, MessageSigner::signatures($message, $signers));

        try {
            $signature = $this->rpc->sendTransaction($wire, $fault->skipsPreflight());
        } catch (RpcException $e) {
            return Outcome::failed(null, $e->failure);
        }

        return $wait ? $this->confirm($signature) : Outcome::sent($signature);
    }

    /**
     * Ask for the signature's status up to `$attempts` times, `$spacingMs`
     * apart, and stop at the first answer. An `err` here is a transaction
     * that landed and failed; attributing it needs the logs
     * `getSignatureStatuses` does not return, so the cause is left unnamed
     * rather than guessed at — see {@see Failure}.
     *
     * A bounded number of asks rather than a deadline, so the cost is stated
     * in the thing it costs: requests to the endpoint. The first ask is made
     * at once and there is no wait after the last. Across the captures of
     * 2026-09-09 to 09-17 the first ask answered on all but three
     * confirmations, and the second on those.
     *
     * @throws RpcException when the endpoint does not answer
     */
    public function confirm(string $signature, bool $history = false): Outcome
    {
        for ($ask = 1; ; $ask++) {
            $outcome = $this->check($signature, $history);
            if ($outcome->status !== SubmitStatus::Unconfirmed || $ask >= $this->attempts) {
                return $outcome;
            }
            usleep($this->spacingMs * 1000);
        }
    }

    /**
     * Ask once. `Unconfirmed` means the cluster had no answer *this time* —
     * not landed yet, or dropped, or landed and not yet at the commitment
     * asked for. Which of those it is takes a later question, or a clock.
     *
     * `$history` is for a question asked after the fact — see
     * {@see Rpc::signatureStatuses()} for why "not found" means nothing
     * without it once a couple of minutes have passed.
     *
     * @throws RpcException when the endpoint does not answer
     */
    public function check(string $signature, bool $history = false): Outcome
    {
        $status = $this->rpc->signatureStatuses([$signature], $history)[0] ?? null;
        if ($status !== null) {
            if ($status['err'] !== null) {
                return Outcome::failed($signature, Failure::fromStatusError($status['err']));
            }
            if (in_array($status['confirmationStatus'], ['confirmed', 'finalized'], true)) {
                return Outcome::confirmed($signature);
            }
        }

        return Outcome::unconfirmed($signature);
    }
}
