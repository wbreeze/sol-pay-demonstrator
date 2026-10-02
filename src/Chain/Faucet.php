<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Store\Store;
use Newsprint\Support\Causes;
use Newsprint\Support\Config;
use SolPay\Core\Base58;
use SolPay\Core\Cause;
use SolPay\Core\CauseKind;
use SolPay\Core\Ids;

/**
 * SPEC §4.3: 0.60 DEMO and 0.01 SOL, once per wallet, paid by the site.
 *
 * The site signs this one, not the reader — which is why it is not a wallet
 * interaction and does not belong in the meter panel's two-step flow. The
 * reader clicks; the server sends. Nothing here needs their signature because
 * nothing here spends their money.
 *
 * Three instructions in one transaction, in this order:
 *
 * 1. **SOL**, because a wallet with no lamports cannot pay rent for the
 *    contract account it is about to open, and the failure it gets instead
 *    names none of that.
 * 2. **The token account, idempotently**, because `approve_checked` against an
 *    account that does not exist fails at the runtime, and because a reader
 *    who comes back after closing a contract must not turn "run it twice" into
 *    a failed transaction.
 * 3. **The DEMO**, minted by the faucet key, which §12.0's setup made the mint
 *    authority for exactly this.
 *
 * §4.3's stinginess is deliberate and worth not quietly fixing: a generous
 * faucet makes §13.2's depleted-balance walkthrough unreachable and deletes
 * one of the two failure modes the demo exists to show.
 */
final class Faucet
{
    public function __construct(
        private readonly Config $config,
        private readonly Submitter $submitter,
        private readonly Store $store,
    ) {
    }

    /**
     * **`message` and `reason` are different things, and both are here on
     * purpose** (2026-09-09). `reason` is what the chain said, scrubbed;
     * `message` is what a reader should be told. They were one field until a
     * faucet failure reached a person as `Transaction simulation failed: Error
     * processing Instruction 0: custom program error: 0x1`, which named a
     * program nobody can see and a code nobody should have to look up — and
     * even that only appeared in a response body, because `tx.js`'s `post()`
     * reads `message` and this route sent neither.
     *
     * `Causes::describe()` is asked first, because when the logs identified
     * the raising program the library already knows the sentence. It answers
     * null for a failure it cannot attribute — a `SystemError` from
     * instruction 0 among them — and the fallback then says the true and
     * useful thing instead of the precise and useless one: this is the site's
     * problem, not the reader's wallet.
     *
     * @return array{granted: bool, reason: string, message: string, signature: ?string}
     */
    public function grant(string $wallet, ?string $remoteAddress = null): array
    {
        // A pasted address, so it is checked as one before anything else: 32
        // bytes of base58. Nothing has been sent, so the form may be tried
        // again.
        if (!self::isAddress($wallet)) {
            $said = 'that is not a wallet address. An address is 32 to 44 letters and digits, as a wallet shows it';

            return ['granted' => false, 'reason' => $said, 'message' => $said, 'signature' => null];
        }

        $addresses = $this->config->provisioned();
        $amounts = $this->config->faucet();
        $program = $this->config->program();

        // The ledger is checked before the chain is touched, and it survives
        // §10.4's erasure for the published reason: the mint is an on-chain
        // transaction naming this account forever, so the row duplicates a
        // public fact. Without it, close-and-refaucet is a loop.
        if ($this->store->faucetGranted($wallet)) {
            // Already a sentence a reader can act on, so it is both fields.
            $spent = 'this wallet has already had its one grant';

            return ['granted' => false, 'reason' => $spent, 'message' => $spent, 'signature' => null];
        }

        $faucet = Keypair::load($this->config->keypairPath('faucet'));

        // §4.3's rate limit per source, asked after the ledger so that a
        // repeated address costs its source nothing, and counted before the
        // send so that a send that fails still counts. The limit keeps the
        // faucet key solvent; what it gives away is worthless.
        if ($remoteAddress !== null) {
            $source = self::source($remoteAddress, $faucet);
            if ($this->store->faucetAttempts($source) >= (int) ($amounts['per_source'] ?? 5)) {
                $said = 'the faucet has sent as often as it will to requests from your network address today. Try again tomorrow';

                return ['granted' => false, 'reason' => $said, 'message' => $said, 'signature' => null];
            }
            $this->store->recordFaucetAttempt($source, (int) ($amounts['source_window_s'] ?? 86_400));
        }

        $tokenAccount = AssociatedToken::address($wallet, $addresses['mint'], $program->tokenProgram);

        try {
            $outcome = $this->send($faucet, $wallet, $tokenAccount, $addresses['mint'], $program->tokenProgram, $amounts);
        } catch (RpcException $e) {
            // The endpoint did not answer. Nothing was sent that this site
            // knows of, and nothing is recorded, so the form may be tried
            // again.
            return [
                'granted' => false,
                'reason' => $e->getMessage(),
                'message' => 'the faucet could not reach devnet just now. Try again in a moment',
                'signature' => null,
            ];
        }

        if (!$outcome->ok()) {
            // Nothing is recorded, so the reader may try again. A failed
            // faucet that burns the one grant is a dead end with no way out.
            return [
                'granted' => false,
                'reason' => $outcome->detail,
                'message' => self::tell($outcome->cause),
                'signature' => $outcome->signature,
            ];
        }

        // Recorded after the send and before the reader is told, which is the
        // same ordering §7.3 gives the metering path: an unconfirmed
        // transaction that may have landed is recorded, because the failure
        // that matters is minting twice, not minting once and forgetting.
        $this->store->recordFaucet($wallet, $outcome->signature);

        $how = $outcome->status === SubmitStatus::Confirmed ? 'confirmed' : $outcome->detail;

        return [
            'granted' => true,
            'reason' => $how,
            'message' => $how,
            'signature' => $outcome->signature,
        ];
    }

    /**
     * @param array<string, int> $amounts
     */
    private function send(Keypair $faucet, string $wallet, string $tokenAccount, string $mint, string $tokenProgram, array $amounts): Outcome
    {
        return $this->submitter->send([
            SystemProgram::transfer($faucet->address, $wallet, (int) $amounts['sol_lamports']),
            AssociatedToken::createIdempotent($faucet->address, $wallet, $mint, $tokenProgram),
            TokenProgram::mintTo($mint, $tokenAccount, $faucet->address, (int) $amounts['demo_base_units'], $tokenProgram),
        ], $faucet);
    }

    public static function isAddress(string $candidate): bool
    {
        if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $candidate) !== 1) {
            return false;
        }
        try {
            return strlen(Base58::decode($candidate)) === 32;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The name the rate limit keeps for an IP address: a hash keyed from the
     * faucet's own secret, which is in a file and not in the database. The
     * store never holds the address, and a copy of the database alone cannot
     * be searched for one (SPEC §10.4).
     */
    private static function source(string $remoteAddress, Keypair $faucet): string
    {
        // Ed25519 signatures are deterministic, so this is the same key every
        // time, it needs the secret to make, and it is published nowhere.
        $key = hash('sha256', $faucet->sign('Newsprint faucet source'), true);

        return substr(hash_hmac('sha256', $remoteAddress, $key), 0, 32);
    }

    /**
     * What to tell a reader when the grant did not happen.
     *
     * **Not simply `Causes::describe()`.** That is the general describer, and
     * its `CauseKind::Unknown` branch is deliberately a non-guess — §8.2's
     * last row, "the program address and the code, and no guess" — because the
     * runtime can raise errors from programs neither this site nor sol-pay
     * anticipated, and naming one would be a lie. Correct there and useless
     * here: on 2026-09-09 a drained faucet told a reader `code 1 from
     * 11111111111111111111111111111111 — a program neither this site nor
     * sol-pay names`, which is precise, true, and no help to anybody.
     *
     * It is useless *because the describer cannot know what this class knows*.
     * `Causes` is handed a code and an address. This class composed the
     * transaction: instruction 0 is a System transfer out of the faucet's own
     * account, so a negative-lamports result there has exactly one meaning,
     * and it is not about the reader's wallet at all.
     *
     * So a named kind is passed straight through — the library's sentence is
     * better than any written here — and an unnamed one is answered from what
     * the caller knows about its own instructions.
     */
    private static function tell(?Cause $cause): string
    {
        $named = $cause !== null && $cause->kind !== CauseKind::Unknown
            ? Causes::describe($cause)
            : null;

        if ($named !== null) {
            return $named;
        }

        // `SystemError::ResultWithNegativeLamports` is 1, and the only System
        // instruction in this transaction is the transfer *from* the faucet.
        // Said without naming the account: §4.3's faucet is not something this
        // site shows a reader, and an address here is furniture they cannot
        // act on. `reason` still carries what the chain said, and
        // `bin/devnet-canary` names the address to the one person who can fund
        // it.
        if ($cause?->unknownProgram === Ids::SYSTEM_PROGRAM_ID && $cause->unknownCode === 1) {
            return 'this site cannot fund a new reader just now — its own account is empty. '
                .'That is this site\'s problem, not your wallet\'s.';
        }

        return 'the faucet could not send. That is this site\'s problem rather than '
            .'anything about your wallet — try again in a moment.';
    }
}
