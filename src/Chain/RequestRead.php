<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Support\Config;
use SolPay\Core\DecodeException;

/**
 * Everything one request knows about the chain, read once.
 *
 * SPEC §12.4 says this design needs "`getMultipleAccounts` — fetch the `Site`,
 * `Contract` and payer token account **in one round trip**". Until 2026-09-10
 * it was two: {@see SiteReader} asked for its three accounts, and then
 * {@see PayerReader} asked for its two, and a HAR that morning put the median
 * round trip at 1242 ms. The second call was not waiting for the first —
 * `PayerReader::addresses()` derives both of its addresses from the wallet and
 * `config->provisioned()`, and reads nothing the *site account* returned. The
 * dependency was in a signature.
 *
 * So this object exists to ask once, and to be the single place that holds the
 * answer for the life of a request. It replaces three by-reference variables
 * and three closures in the front controller, which is where every capture
 * fault in this project has come from.
 *
 * **Two rules make it safe, and they pull in opposite directions.**
 *
 * 1. *Read once.* Everything on the page — the prices in the copy, the meter
 *    panel, §9's inspector — is the same read, so the page cannot contradict
 *    itself and cannot be charged twice for agreeing with itself.
 * 2. *Except when the chain moved underneath it.* A metering call that sends a
 *    transaction changes `used`, `paid` and the carried residue, and §2's
 *    claim 7 is that every number on the screen came from an account rather
 *    than from the server's memory. {@see invalidatePayer()} is how the
 *    metering path says so, and the re-read it causes is claim 7's price
 *    rather than an inefficiency.
 *
 * A read that never happens costs nothing: {@see hasRead()} answers §9's
 * "did this request read the chain for its own reasons" without performing
 * one, which is what keeps `/privacy` at zero calls.
 */
final class RequestRead
{
    private bool $read = false;
    private bool $payerStale = false;
    private ?SiteState $site = null;
    private ?PayerState $payer = null;
    private ?string $error = null;

    /**
     * @param ?string $wallet the session's paying wallet, or null — known from
     *                        the cookie and the store, so it costs no round
     *                        trip and can be decided before the first one
     */
    public function __construct(
        private readonly Config $config,
        private readonly Rpc $rpc,
        private readonly ?string $wallet,
    ) {
    }

    /** The site's accounts, decoded. Null when this copy is unprovisioned or the read failed. */
    public function site(): ?SiteState
    {
        $this->run();

        return $this->site;
    }

    /**
     * The reader's contract and token account, decoded.
     *
     * Null when nobody is identified, when the site could not be read (there
     * is no `Site` to decode a contract against), or when the read failed.
     */
    public function payer(): ?PayerState
    {
        $this->run();

        if ($this->payerStale) {
            $this->rereadPayer();
        }

        return $this->payer;
    }

    /** What the endpoint said when it refused, for §9's panel to report. */
    public function error(): ?string
    {
        $this->run();

        return $this->error;
    }

    /** The paying wallet, which is known without asking the chain anything. */
    public function wallet(): ?string
    {
        return $this->wallet;
    }

    /**
     * Has this request already paid for a chain read?
     *
     * **Asking must not cause one**, which is the whole point: §9 renders the
     * inspector inline where the request read the chain for its own reasons
     * and defers it to `GET /inspector/panel` where it did not. `site() !== null`
     * would answer the same question by performing the read it is asking
     * about, and that is what cost `/privacy` 0.899 s until 2026-09-09.
     */
    public function hasRead(): bool
    {
        return $this->read;
    }

    /**
     * Take a newer reading of the payer's accounts than this object has.
     *
     * The metering path reads the contract and the token account again inside
     * the payer lock (§7.2), because a request that queued behind another must
     * decide from what that one left rather than from what it saw before
     * waiting. Where that call then sends nothing — `can_meter` refused — the
     * locked read is simply better than the one taken here a few milliseconds
     * earlier, and the screen explaining a refusal should state the arithmetic
     * the refusal was made from. Anything else leaves room for a limit screen
     * that disagrees with the decision it is reporting.
     */
    public function adopt(PayerState $payer): void
    {
        $this->run();
        $this->payer = $payer;
        $this->payerStale = false;
    }

    /**
     * Say that a transaction went out, so the payer's accounts are no longer
     * what this object read.
     *
     * Deliberately lazy: it marks rather than re-reads, so a request that
     * never asks about the payer again never buys the call. On the article
     * route it always asks — the strip reports what was just charged — so in
     * practice this is claim 7's one round trip, taken once, after the send.
     */
    public function invalidatePayer(): void
    {
        // Only where something has already been read. A route that confirms a
        // transaction *before* it asks about the payer — `/meter/opened` and
        // `/meter/close/done` both do — is already going to read after the
        // event, and forcing a second one would buy the same bytes twice. This
        // makes the call safe to place wherever the chain is known to have
        // moved, without having to know what has happened on the request yet.
        $this->payerStale = $this->read;
    }

    /**
     * One round trip for up to five accounts, or none at all.
     *
     * The site's three and the payer's two go in the same
     * `getMultipleAccounts`, in that order, and each reader decodes its own
     * slice — this class batches addresses and splits results, and knows what
     * neither account looks like.
     */
    private function run(): void
    {
        if ($this->read) {
            return;
        }
        $this->read = true;

        if (!$this->config->isProvisioned()) {
            return;
        }

        $siteReader = new SiteReader($this->config, $this->rpc);
        $payerReader = new PayerReader($this->config, $this->rpc);

        $siteAddresses = $siteReader->addresses();
        $payerAddresses = $this->wallet === null ? [] : $payerReader->addresses($this->wallet);

        try {
            $accounts = $this->rpc->multipleAccounts([...$siteAddresses, ...$payerAddresses]);

            $this->site = $siteReader->decode(array_slice($accounts, 0, count($siteAddresses)));

            // No `Site` is not an error and not a half-read: an unprovisioned
            // copy, or a `var/site.json` from another cluster. There is
            // nothing to decode a contract against, so the payer stays null
            // and every screen falls back to what config says.
            if ($this->site !== null && $this->wallet !== null) {
                $this->payer = $payerReader->decode(
                    $this->wallet,
                    $payerAddresses,
                    array_slice($accounts, count($siteAddresses)),
                    $this->site,
                );
            }
        } catch (RpcException|DecodeException $e) {
            // An unmetered page owes the chain nothing, so the site keeps
            // serving and the panel says the read failed (§9). The metering
            // path is where this stops being survivable, and it says so there.
            $this->error = $e->getMessage();
        }
    }

    /** The payer's two accounts only. The site does not change between these two moments. */
    private function rereadPayer(): void
    {
        $this->payerStale = false;

        if ($this->site === null || $this->wallet === null) {
            return;
        }

        try {
            $this->payer = (new PayerReader($this->config, $this->rpc))->read($this->wallet, $this->site);
        } catch (RpcException|DecodeException $e) {
            $this->payer = null;
            $this->error = $e->getMessage();
        }
    }
}
