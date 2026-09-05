<?php

declare(strict_types=1);

namespace Newsprint\Setup;

use Newsprint\Chain\AssociatedToken;
use Newsprint\Chain\Keypair;
use Newsprint\Chain\Outcome;
use Newsprint\Chain\Rpc;
use Newsprint\Chain\RpcException;
use Newsprint\Chain\Submitter;
use Newsprint\Chain\SystemProgram;
use Newsprint\Chain\TokenProgram;
use Newsprint\Support\Config;
use SolPay\Core\Ix;
use SolPay\Core\Pda;

/**
 * First-run setup (SPEC §12.0 and §4).
 *
 * Creates the DEMO mint, the treasury, and the site account, and records the
 * addresses. It is the only worked example of `initialize_site` anywhere, and
 * §3 makes it a screen rather than a command: every call it needs is available
 * over JSON-RPC including `requestAirdrop`, so an operator needs one language
 * runtime and no toolchain.
 *
 * **Resumable, because the alternative is expensive.** Each step asks the
 * chain whether its work is already done, and records what it created as it
 * goes. A run interrupted after the mint and before `initialize_site` — a
 * refused airdrop, a closed laptop, a rate limit — picks up where it stopped
 * rather than creating a second mint and abandoning the rent on the first.
 *
 * **It never runs against a provisioned site.** The separation §3 wanted from
 * an operator CLI is enforced here instead: this class refuses when the site
 * account already exists, which is a stronger guarantee than a command nobody
 * happens to run twice.
 */
final class Provisioner
{
    public function __construct(
        private readonly Config $config,
        private readonly Rpc $rpc,
        private readonly Submitter $submitter,
    ) {
    }

    /** What the screen shows before anything is done. @return array<string, mixed> */
    public function status(): array
    {
        $authority = Keypair::loadOrCreate($this->config->keypairPath('authority'));
        $faucet = Keypair::loadOrCreate($this->config->keypairPath('faucet'));
        $setup = $this->config->setup();

        $balance = $this->rpc->balance($authority->address);

        return [
            'program_deployed' => $this->rpc->programDeployed($this->config->program()->id),
            'program' => $this->config->program()->id,
            'authority' => $authority->address,
            'faucet' => $faucet->address,
            'balance' => $balance,
            'needed' => (int) $setup['authority_minimum_lamports'] + (int) $setup['faucet_reserve_lamports'],
            'funded' => $balance >= (int) $setup['authority_minimum_lamports'] + (int) $setup['faucet_reserve_lamports'],
            'provisioned' => $this->config->isProvisioned(),
            'partial' => $this->config->partial(),
        ];
    }

    /** @return list<Step> in the order they ran; the first stopping step is the last */
    public function run(): array
    {
        if ($this->config->isProvisioned()) {
            return [Step::blocked('site', 'This site is already provisioned. Setup will not run twice.')];
        }

        $steps = [];
        $authority = Keypair::loadOrCreate($this->config->keypairPath('authority'));
        $faucet = Keypair::loadOrCreate($this->config->keypairPath('faucet'));
        $program = $this->config->program();
        $params = $this->config->siteParams();
        $setup = $this->config->setup();

        $steps[] = Step::done('keys', 'Site authority and faucet key, generated here and never committed (§4.4).', null, $authority->address);

        // --- funding ------------------------------------------------------
        $step = $this->fund($authority, (int) $setup['authority_minimum_lamports'] + (int) $setup['faucet_reserve_lamports'], (int) $setup['airdrop_lamports']);
        $steps[] = $step;
        if ($step->stops()) {
            return $steps;
        }

        // §4.3's faucet pays visitors from its own key rather than routing
        // them to the public devnet faucet, so it needs SOL of its own. The
        // operator funds one address; setup moves the reserve to the other.
        if ($this->rpc->balance($faucet->address) < (int) $setup['faucet_reserve_lamports']) {
            $outcome = $this->submitter->send(
                [SystemProgram::transfer($authority->address, $faucet->address, (int) $setup['faucet_reserve_lamports'])],
                $authority,
            );
            $steps[] = $this->fromOutcome('faucet reserve', $outcome, sprintf(
                '%s SOL moved to the faucet key, which is what pays visitors (§4.3).',
                rtrim(rtrim(number_format((int) $setup['faucet_reserve_lamports'] / 1_000_000_000, 9, '.', ''), '0'), '.'),
            ), $faucet->address);
            if (end($steps)->stops()) {
                return $steps;
            }
        } else {
            $steps[] = Step::already('faucet reserve', 'The faucet key already holds enough SOL.', $faucet->address);
        }

        // --- the mint (§4.1) ----------------------------------------------
        $recorded = $this->config->partial();
        $mintAddress = $recorded['mint'] ?? null;

        if ($mintAddress !== null && $this->rpc->accountExists($mintAddress)) {
            $steps[] = Step::already('mint', 'The DEMO mint already exists.', $mintAddress);
        } else {
            $mint = Keypair::loadOrCreate($this->config->keypairPath('mint'));
            $rent = $this->rpc->minimumBalanceForRentExemption(TokenProgram::MINT_LEN);

            // Two instructions, one transaction: an account the token program
            // owns, and then the mint inside it. Split across two
            // transactions, a failure between them strands a funded account
            // nothing can initialize.
            $outcome = $this->submitter->send([
                SystemProgram::createAccount($authority->address, $mint->address, $rent, TokenProgram::MINT_LEN, $program->tokenProgram),
                TokenProgram::initializeMint2($mint->address, (int) $params['decimals'], $faucet->address, null, $program->tokenProgram),
            ], $authority, [$mint]);

            $steps[] = $this->fromOutcome('mint', $outcome, sprintf(
                '%s created, %d decimals, mint authority the faucet key and no freeze authority. Six decimals because USDC has six (§4.1).',
                (string) $params['symbol'],
                (int) $params['decimals'],
            ), $mint->address);
            if (end($steps)->stops()) {
                return $steps;
            }

            $this->config->writeProvisioned(['mint' => $mint->address]);
            $mintAddress = $mint->address;
        }

        // --- the treasury -------------------------------------------------
        $treasury = AssociatedToken::address($authority->address, $mintAddress, $program->tokenProgram);

        if ($this->rpc->accountExists($treasury)) {
            $steps[] = Step::already('treasury', 'The treasury token account already exists.', $treasury);
        } else {
            $outcome = $this->submitter->send(
                [AssociatedToken::createIdempotent($authority->address, $authority->address, $mintAddress, $program->tokenProgram)],
                $authority,
            );
            $steps[] = $this->fromOutcome('treasury', $outcome, 'The site authority\'s associated token account for the mint — where settled payments land.', $treasury);
            if (end($steps)->stops()) {
                return $steps;
            }
        }
        $this->config->writeProvisioned(['treasury' => $treasury]);

        // --- the program has to be there (§12.0) --------------------------
        // "Nobody but the publisher builds the program": `pay-on-chain` is
        // deployed to devnet once, and one deployment serves many sites
        // because the `Site` PDA is seeded by authority. Until that deployment
        // exists, `initialize_site` fails inside simulation with "Attempt to
        // load a program that does not exist" — true, unhelpful, and about the
        // program rather than about anything setup did.
        if (!$this->rpc->programDeployed($program->id)) {
            $steps[] = Step::blocked('program', sprintf(
                'The metering program is not deployed on this cluster, or this is not the address it was deployed to. '
                .'Deploy pay-on-chain to devnet, then make sure program.id in config/site.php is the deployed address — '
                .'a fresh `anchor build` generates a keypair that does not match `declare_id!` unless `anchor keys sync` has run. '
                .'Everything above this line is done and will not be repeated.',
            ), $program->id);

            return $steps;
        }

        // --- the site (§4.2) ----------------------------------------------
        $site = Pda::siteAddress($authority->address, $program->id)['address'];

        if ($this->rpc->accountExists($site)) {
            $steps[] = Step::already('site', 'The site account already exists on this deployment.', $site);
        } else {
            $outcome = $this->submitter->send([
                Ix::initializeSite(
                    $program,
                    $authority->address,
                    $mintAddress,
                    $treasury,
                    (int) $params['page_price'],
                    (int) $params['collection_threshold'],
                    (int) $params['min_limit'],
                ),
            ], $authority);

            $steps[] = $this->fromOutcome('site', $outcome, 'Pricing written on chain: page price, collection threshold and minimum limit (§4.2).', $site);
            if (end($steps)->stops()) {
                return $steps;
            }
        }

        $this->config->writeProvisioned([
            'authority' => $authority->address,
            'faucet' => $faucet->address,
            'mint' => $mintAddress,
            'treasury' => $treasury,
            'site' => $site,
        ]);

        return $steps;
    }

    /**
     * Devnet's faucet refuses more often than it works and its refusal is
     * generic — a depleted faucet, a per-address limit and a per-IP one all
     * arrive as the same message. So a refusal is not an error here: it is a
     * step that tells the operator which address to fund and stops.
     */
    private function fund(Keypair $authority, int $needed, int $airdrop): Step
    {
        $balance = $this->rpc->balance($authority->address);
        if ($balance >= $needed) {
            return Step::already('funding', sprintf('The authority holds %s SOL.', self::sol($balance)), $authority->address);
        }

        try {
            $this->rpc->requestAirdrop($authority->address, $airdrop);
            for ($i = 0; $i < 30 && $this->rpc->balance($authority->address) <= $balance; $i++) {
                usleep(500_000);
            }
            $balance = $this->rpc->balance($authority->address);
        } catch (RpcException) {
            // Deliberately swallowed: the balance check below is the real
            // question, and the endpoint's reason would be "Internal error".
        }

        if ($balance >= $needed) {
            return Step::done('funding', sprintf('Airdropped. The authority holds %s SOL.', self::sol($balance)), null, $authority->address);
        }

        return Step::blocked('funding', sprintf(
            'The authority holds %s SOL and setup needs %s. Devnet\'s faucet would not supply it — it refuses more often than it works and does not say why. Fund this address and run setup again; the key is kept, so the address will not change.',
            self::sol($balance),
            self::sol($needed),
        ), $authority->address);
    }

    private function fromOutcome(string $name, Outcome $outcome, string $detail, ?string $address = null): Step
    {
        if (!$outcome->ok()) {
            return Step::failed($name, $outcome->detail, $outcome->signature);
        }

        return Step::done($name, $detail, $outcome->signature, $address);
    }

    private static function sol(int $lamports): string
    {
        return rtrim(rtrim(number_format($lamports / 1_000_000_000, 9, '.', ''), '0'), '.') ?: '0';
    }
}
