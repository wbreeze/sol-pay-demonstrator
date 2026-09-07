<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Store\Store;
use Newsprint\Support\Config;

/**
 * SPEC §4.3: 0.60 DEMO and 0.05 SOL, once per wallet, paid by the site.
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
     * @return array{granted: bool, reason: string, signature: ?string}
     */
    public function grant(string $wallet): array
    {
        $addresses = $this->config->provisioned();
        $amounts = $this->config->faucet();
        $program = $this->config->program();

        // The ledger is checked before the chain is touched, and it survives
        // §10.4's erasure for the published reason: the mint is an on-chain
        // transaction naming this account forever, so the row duplicates a
        // public fact. Without it, close-and-refaucet is a loop.
        if ($this->store->faucetGranted($wallet)) {
            return ['granted' => false, 'reason' => 'this wallet has already had its one grant', 'signature' => null];
        }

        $faucet = Keypair::load($this->config->keypairPath('faucet'));
        $tokenAccount = AssociatedToken::address($wallet, $addresses['mint'], $program->tokenProgram);

        $outcome = $this->submitter->send([
            SystemProgram::transfer($faucet->address, $wallet, (int) $amounts['sol_lamports']),
            AssociatedToken::createIdempotent($faucet->address, $wallet, $addresses['mint'], $program->tokenProgram),
            TokenProgram::mintTo(
                $addresses['mint'],
                $tokenAccount,
                $faucet->address,
                (int) $amounts['demo_base_units'],
                $program->tokenProgram,
            ),
        ], $faucet);

        if (!$outcome->ok()) {
            // Nothing is recorded, so the reader may try again. A failed
            // faucet that burns the one grant is a dead end with no way out.
            return ['granted' => false, 'reason' => $outcome->detail, 'signature' => $outcome->signature];
        }

        // Recorded after the send and before the reader is told, which is the
        // same ordering §7.3 gives the metering path: an unconfirmed
        // transaction that may have landed is recorded, because the failure
        // that matters is minting twice, not minting once and forgetting.
        $this->store->recordFaucet($wallet, $outcome->signature);

        return [
            'granted' => true,
            'reason' => $outcome->status === SubmitStatus::Confirmed ? 'confirmed' : $outcome->detail,
            'signature' => $outcome->signature,
        ];
    }
}
