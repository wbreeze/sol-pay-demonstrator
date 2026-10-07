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
 * Renewing and *add to the fund* are scans, through the same pending setup
 * and the same route as setup (SPEC §6.3, §6.4).
 *
 * @var string $stage
 * @var array<string, int|string> $site
 * @var int $live_grants
 * @var array{limit: string, deposit: string, expiries: list<string>, dev_wallet: bool} $setup
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
    <script type="module" src="<?= $view->asset('/assets/key.js') ?>"></script>

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

    </section>

    <section class="gate meter" id="renew" data-close-controls>
        <h2>Renew the meter</h2>
        <p>
            Renewing sets a new limit and a new expiry, carries what is unpaid
            forward as the new period's <code>used</code>, and resets
            <code>paid</code> to zero. Your wallet signs the renewal, with one scan.
        </p>
        <form class="setup" data-setup-form data-kind="renew" hidden>
            <label class="limit">
                Limit
                <input type="text" inputmode="decimal" name="limit" value="<?= View::e((string) $setup['limit']) ?>" size="8">
                <?= $symbol ?>
            </label>
            <p class="fine">At least <?= View::e((string) $setup['limit']) ?> <?= $symbol ?>, which folds in the <?= View::e((string) $meter['unpaid']) ?> you are carrying.</p>
            <fieldset class="expiry">
                <legend>The meter expires after</legend>
                <label><input type="radio" name="expiry" value="hour"> an hour</label>
                <label><input type="radio" name="expiry" value="day" checked> a day</label>
                <label><input type="radio" name="expiry" value="week"> a week</label>
                <label><input type="radio" name="expiry" value="month"> thirty days</label>
            </fieldset>
            <label class="limit">
                Deposit
                <input type="text" inputmode="decimal" name="deposit" value="0" size="8">
                <?= $symbol ?>
            </label>
            <?php /* Why the scan did not start, filled by `key.js`. Above the button,
                     because the button is what the reader is looking at. */ ?>
            <p class="refusal" data-setup-refusal role="alert" hidden>
                <strong>Nothing was sent to your wallet:</strong>
                <span data-setup-reason></span>.
            </p>
            <p><button type="submit" class="wallet">Renew</button></p>
        </form>

        <h2 id="add">Add to the fund</h2>
        <p>
            A deposit alone, touching no meter: the limit, the expiry and what
            has been used stay as they are.
        </p>
        <form class="setup" data-setup-form data-kind="deposit" hidden>
            <label class="limit">
                Deposit
                <input type="text" inputmode="decimal" name="deposit" value="<?= View::e((string) $setup['deposit']) ?>" size="8">
                <?= $symbol ?>
            </label>
            <?php /* Why the scan did not start, filled by `key.js`. Above the button,
                     because the button is what the reader is looking at. */ ?>
            <p class="refusal" data-setup-refusal role="alert" hidden>
                <strong>Nothing was sent to your wallet:</strong>
                <span data-setup-reason></span>.
            </p>
            <p><button type="submit" class="wallet">Add to the fund</button></p>
        </form>
<?= $view->render('setup-scan', ['symbol' => $symbol, 'development' => $setup['dev_wallet']]) ?>
        <noscript><p class="pending">Renewing and adding to the fund need JavaScript: the scan starts from this page's script.</p></noscript>
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
    <script type="module" src="<?= $view->asset('/assets/key.js') ?>"></script>
<?php endif ?>

    <p class="fine"><a href="/">Back to the paper</a> · <a href="/privacy">What this site holds about you</a></p>
</article>
