<?php
/**
 * `manage_meter` (§6): what the meter has spent, and where it stands.
 *
 * Reachable at any time, decided 2026-09-02, because a reader who has let a
 * site draw on their fund may reasonably expect to find, at any moment, a page
 * that says what they have spent and offers a way out.
 *
 * **The way out is closing the meter** (SPEC §5.4), signed by this browser's
 * key. The close section carries §10.4's disclosures **before** the button,
 * not after it, because each of them could change the decision:
 *
 * 1. what is deleted, and that the session goes with it;
 * 2. that it costs the articles already paid for, and how many;
 * 3. that the faucet ledger survives, and why that exception is allowed;
 * 4. that closing is itself written to a public ledger, permanently — the
 *    instruction to be forgotten is recorded by the act of giving it.
 *
 * Renewing by a scan follows with the setup it shares (SPEC §6.3).
 *
 * @var string $stage
 * @var array<string, int|string> $site
 * @var int $live_grants
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Support\View;

$symbol = View::e((string) ($symbol ?? $site['symbol']));
?>
<article class="piece">
    <h1>The meter</h1>

<?php if ($stage === 'anonymous'): ?>
    <p class="lede">
        This browser holds no meter for this site, so there is nothing here to
        manage.
    </p>
<?php if ($ended ?? false): ?>
    <p class="pending">
        The meter this browser held has been closed, or renewed from another
        device, which then holds it. This browser's session with it has ended.
    </p>
<?php endif ?>
    <p class="pending" data-key-bind role="status" hidden></p>
    <p><a href="/">Go and read something</a>.</p>
    <script type="module" src="/assets/key.js"></script>

<?php elseif ($stage === 'unreadable'): ?>
    <p class="lede">The chain could not be read just now, so this page cannot say where you stand.</p>
    <p class="pending">Nothing was charged and nothing was changed. Try again in a moment.</p>

<?php else: ?>
    <section class="gate meter">
        <p class="fine">
            Meter <code><?= View::e(substr((string) $meter['address'], 0, 4).'…'.substr((string) $meter['address'], -4)) ?></code>
        </p>

        <table class="status">
            <tr><th scope="row">limit</th><td><?= View::e((string) $meter['limit']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">used</th><td><?= View::e((string) $meter['used']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">settled</th><td><?= View::e((string) $meter['paid']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">unpaid</th><td><?= View::e((string) $meter['unpaid']) ?> <?= $symbol ?> — carried, not owed until it settles</td></tr>
            <tr><th scope="row">expires</th><td><?= View::e((string) $meter['expiry']) ?></td></tr>
            <tr><th scope="row">fund balance</th><td><?= View::e((string) $balance) ?> <?= $symbol ?></td></tr>
<?php if ($items_remaining !== null): ?>
            <tr><th scope="row">views left</th><td><?= View::e((string) $items_remaining) ?></td></tr>
<?php endif ?>
        </table>

<?php if ($blocked !== null): ?>
        <p class="pending">
            The meter is blocked: <code><?= View::e((string) $blocked) ?></code>.
        </p>
<?php endif ?>

        <p class="pending">
            Renewing the meter is being rebuilt for the fund design, and is not
            offered here yet.
        </p>
    </section>

    <section class="gate meter" id="close">
        <h2>Close this meter</h2>
        <p>
            Closing ends the meter. This site can draw nothing more from your
            fund through it, and the meter's rent returns to the wallet that
            opened the fund. The fund itself stays, with whatever it holds,
            and is yours to withdraw from with that wallet.
        </p>
        <p>
            The <?= View::e((string) $meter['unpaid']) ?> <?= $symbol ?>
            the meter carries is <strong>forgiven</strong>, not collected. The
            site takes that loss on purpose, and pays the close's fee.
        </p>

        <h3>What closing deletes</h3>
        <ul class="plan">
            <li>Your <strong>session</strong>: the row tying this browser to the meter.</li>
            <li>
                Your <strong>view grants</strong> —
<?php if ($live_grants > 0): ?>
                <?= View::e((string) $live_grants) ?> live right now.
                <strong>Those articles stop being served</strong>, even though
                you have paid for them. That is the cost, it is bounded at
                <?= View::e((string) $site['page_price_demo']) ?> <?= $symbol ?>
                each, and you are being told before you click rather than after.
<?php else: ?>
                none are live right now, so this costs you nothing today.
<?php endif ?>
            </li>
            <li>
                <strong>This browser's key</strong>, which the page deletes once
                the close has landed.
            </li>
            <li>
                The <strong>faucet ledger row survives</strong>, and the reason
                is published rather than assumed: the faucet's transfer is an
                on-chain transaction naming your address forever, so the row
                duplicates a public fact. Without it, close-and-refaucet would
                be a loop.
            </li>
        </ul>

        <h3>What closing writes</h3>
        <p>
            <code>close_meter</code> emits <code>Closed { meter, forgiven }</code>
            to the chain. Your instruction to be forgotten is itself recorded,
            publicly and permanently, by the act of giving it. That is not an
            argument against closing. It is the clearest thing this site can
            show you about what a public ledger is.
        </p>

        <div data-close-controls>
            <?php /* Hidden until the script runs: the key that signs the close
                     lives in the script's storage, so without JavaScript there
                     is nothing to sign with. */ ?>
            <p><button type="button" class="wallet" data-close-meter hidden>Close this meter</button></p>
            <noscript><p class="pending">Closing the meter needs JavaScript: the key that signs the close is kept by this page's script.</p></noscript>
        </div>
        <p class="pending" data-close-status role="status" hidden></p>
    </section>
    <script type="module" src="/assets/key.js"></script>
<?php endif ?>

    <p class="fine"><a href="/">Back to the paper</a> · <a href="/privacy">What this site holds about you</a></p>
</article>
