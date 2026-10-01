<?php

declare(strict_types=1);

namespace Newsprint\Chain;

use Newsprint\Auth\Binding;
use Newsprint\Support\Config;
use SolPay\Core\DecodeException;

/**
 * Everything one request knows about the chain, read once.
 *
 * SPEC §6.2's diagram reads the site, the treasury, the mint, the meter and the
 * fund's token account in one `getMultipleAccounts`. Until 2026-09-10 the
 * delegate design's equivalent took two calls, the site's and then the
 * payer's, and a HAR that morning put the median round trip at 1242 ms. The
 * second call was never waiting for the first, because none of the reader's
 * addresses depends on what the *site account* says. {@see MeterReader} keeps
 * that property: the meter's address and the fund's come from the session.
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
 *    than from the server's memory. {@see invalidateMeter()} is how the
 *    metering path says so, and the re-read it causes is claim 7's price
 *    rather than an inefficiency.
 *
 * **And every read asks whether the session still holds the meter** (SPEC
 * §5.3). A meter that is gone, or that names another browser's key, ends the
 * session at the read that finds it. The check is here rather than at each
 * route so that no route can read a meter and forget to ask.
 *
 * A read that never happens costs nothing: {@see hasRead()} answers §9's
 * "did this request read the chain for its own reasons" without performing
 * one, which is what keeps `/privacy` at zero calls.
 */
final class RequestRead
{
    private bool $read = false;
    private bool $meterStale = false;
    private bool $ended = false;
    private ?SiteState $site = null;
    private ?MeterState $meter = null;
    private ?string $error = null;

    /** @var (callable(Binding): void)|null */
    private $end;

    /**
     * @param ?Binding                     $binding the session's meter, fund and key,
     *                                              or null. Known from the cookie
     *                                              and the store, so it costs no
     *                                              round trip and can be decided
     *                                              before the first one
     * @param (callable(Binding): void)|null $end   called once, when a read finds
     *                                              that the meter no longer binds
     *                                              this session (SPEC §5.3)
     */
    public function __construct(
        private readonly Config $config,
        private readonly Rpc $rpc,
        private ?Binding $binding,
        ?callable $end = null,
    ) {
        $this->end = $end;
    }

    /** The site's accounts, decoded. Null when this copy is unprovisioned or the read failed. */
    public function site(): ?SiteState
    {
        $this->run();

        return $this->site;
    }

    /**
     * The session's meter and its fund's token account, decoded.
     *
     * Null when no session holds a meter, when the site could not be read
     * (there is no `Site` to decode against), when the read failed, and when
     * the read ended the session.
     */
    public function meter(): ?MeterState
    {
        $this->run();

        if ($this->meterStale) {
            $this->rereadMeter();
        }

        return $this->meter;
    }

    /** What the endpoint said when it refused, for §9's panel to report. */
    public function error(): ?string
    {
        $this->run();

        return $this->error;
    }

    /**
     * The session's meter, fund and key, which are known without asking the
     * chain anything. Null once a read has ended the session.
     */
    public function binding(): ?Binding
    {
        return $this->binding;
    }

    /** Whether a read on this request found the meter gone or another key's, and ended the session. */
    public function ended(): bool
    {
        return $this->ended;
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
     * Take a newer reading of the meter than this object has.
     *
     * The metering path reads the meter and the token account again inside
     * the meter lock (§7.2), because a request that queued behind another must
     * decide from what that one left rather than from what it saw before
     * waiting. Where that call then sends nothing — the preflight refused — the
     * locked read is simply better than the one taken here a few milliseconds
     * earlier, and the screen explaining a refusal should state the arithmetic
     * the refusal was made from.
     */
    public function adopt(MeterState $meter): void
    {
        $this->run();
        $this->meter = $meter;
        $this->meterStale = false;
        $this->check();
    }

    /**
     * Say that a transaction went out, so the meter's accounts are no longer
     * what this object read.
     *
     * Deliberately lazy: it marks rather than re-reads, so a request that
     * never asks about the meter again never buys the call. On the article
     * route it always asks — the strip reports what was just charged — so in
     * practice this is claim 7's one round trip, taken once, after the send.
     */
    public function invalidateMeter(): void
    {
        // Only where something has already been read. A route that confirms a
        // transaction *before* it asks about the meter is already going to
        // read after the event, and forcing a second read would buy the same
        // bytes twice.
        $this->meterStale = $this->read;
    }

    /**
     * One round trip for up to five accounts, or none at all.
     *
     * The site's three and the meter's two go in the same
     * `getMultipleAccounts`, in that order, and each reader decodes its own
     * slice. This class batches addresses and splits results, and knows what
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
        $meterReader = new MeterReader($this->config, $this->rpc);

        $siteAddresses = $siteReader->addresses();
        $meterAddresses = $this->binding === null ? [] : $meterReader->addresses($this->binding);

        try {
            $accounts = $this->rpc->multipleAccounts([...$siteAddresses, ...$meterAddresses]);

            $this->site = $siteReader->decode(array_slice($accounts, 0, count($siteAddresses)));

            // No `Site` is not an error and not a half-read: an unprovisioned
            // copy, or a `var/site.json` from another cluster. There is
            // nothing to decode a meter against, so the meter stays null and
            // every screen falls back to what config says.
            if ($this->site !== null && $this->binding !== null) {
                $this->meter = $meterReader->decode(
                    $this->binding,
                    $meterAddresses,
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

        $this->check();
    }

    /** The meter's two accounts only. The site does not change between these two moments. */
    private function rereadMeter(): void
    {
        $this->meterStale = false;

        if ($this->site === null || $this->binding === null) {
            return;
        }

        try {
            $this->meter = (new MeterReader($this->config, $this->rpc))->read($this->binding, $this->site);
        } catch (RpcException|DecodeException $e) {
            $this->meter = null;
            $this->error = $e->getMessage();
        }

        $this->check();
    }

    /**
     * SPEC §5.3: the chain ends sessions. A meter that was read and does not
     * bind this session (closed, or renewed to another device's key) ends it,
     * and from then on this request has no meter to show or to charge.
     *
     * A meter that could not be read is not one that was found wanting, so a
     * failed read ends nothing.
     */
    private function check(): void
    {
        if ($this->binding === null || $this->meter === null || $this->meter->binds($this->binding)) {
            return;
        }

        $binding = $this->binding;
        $this->binding = null;
        $this->meter = null;
        $this->ended = true;

        if ($this->end !== null) {
            ($this->end)($binding);
        }
    }
}
