<?php
/**
 * The meter, on the article, where the decision to spend is actually made.
 *
 * This is `set_meter` from sol-pay's state diagram for a browser with no
 * meter, and `manage_meter`'s short form for one whose meter cannot take the
 * next charge. There is no sign-in screen (SPEC §5.6): identifying happens
 * here, beside the money.
 *
 * **Setting a meter is a scan** (SPEC §6.3). The panel asks four questions,
 * the page makes its key, and a wallet fetches the transaction through a
 * Solana Pay link. A browser that already holds a key and a meter proves the
 * key here instead and is bound a session (SPEC §5.3).
 *
 * @var array<string, mixed> $meter
 * @var array<string, int|string> $site
 */
use Newsprint\Support\Causes;
use Newsprint\Support\View;
use SolPay\Core\Units;

$symbol = View::e((string) $meter['symbol']);
$onChain = $meter['meter'];
?>
<section class="gate meter" data-meter
         data-stage="<?= View::e((string) $meter['stage']) ?>">

<?php if (!$meter['provisioned']): ?>
    <h2>The rest is metered</h2>
    <p class="pending">This copy has not been set up yet. <a href="/setup">First run</a> creates the site on devnet.</p>

<?php elseif ($meter['stage'] === 'unreadable'): ?>
    <h2>The rest is metered</h2>
    <p class="pending">
        Currently unable to consult the meter.
    </p>

<?php elseif ($meter['stage'] === 'anonymous'): ?>
    <h2>The rest is metered</h2>
    <p>
        Reading on costs <?= View::e((string) $site['page_price_demo']) ?> <?= $symbol ?>
        an article, drawn from a fund you control, under a limit and an expiry
        you set yourself.
    </p>
<?php if ($meter['ended']): ?>
    <p class="pending">
        The meter this browser held has been closed, or renewed from another
        device, which then holds it. This browser's session with it has ended.
    </p>
<?php endif ?>
    <?php /* §5.3: a browser that holds a key and a meter address proves the
             key here, with no wallet, and the page reloads with a session.
             The script fills this line when it has something to say. */ ?>
    <p class="pending" data-key-bind role="status" hidden></p>

    <?php /* `set_meter`, SPEC §6.3 step 1: inform the cost, ask four
             questions, enforce the minimum, all before the wallet sees
             anything. The expiry is a choice among four, never a date. */ ?>
    <form class="setup" data-setup-form data-kind="setup" hidden>
        <p>
            Your wallet opens a <strong>fund</strong>, a pocket of
            <?= $symbol ?> that sites draw from, and this site's
            <strong>meter</strong> on it. The meter can take no more than its
            limit, and nothing after its expiry. Your wallet signs once; after
            that, reading needs nothing from it.
        </p>
        <label class="limit">
            Limit
            <input type="text" inputmode="decimal" name="limit" value="<?= View::e((string) $meter['setup']['limit']) ?>" size="8">
            <?= $symbol ?>
        </label>
        <p class="fine">At least <?= View::e((string) $meter['setup']['limit']) ?> <?= $symbol ?>. The limit is trust, not pacing: a site can draw straight to it.</p>

        <fieldset class="expiry">
            <legend>The meter expires after</legend>
            <label><input type="radio" name="expiry" value="hour"> an hour</label>
            <span class="fine">for a machine you do not own: after the hour, the key left in this browser opens nothing.</span>
            <label><input type="radio" name="expiry" value="day" checked> a day</label>
            <span class="fine">one sitting, with room to come back.</span>
            <label><input type="radio" name="expiry" value="week"> a week</label>
            <span class="fine">your own device.</span>
            <label><input type="radio" name="expiry" value="month"> thirty days</label>
            <span class="fine">your own device, read often. Until then, anyone who uses this browser can read on this meter.</span>
        </fieldset>

        <label class="limit">
            Deposit
            <input type="text" inputmode="decimal" name="deposit" value="<?= View::e((string) $meter['setup']['deposit']) ?>" size="8">
            <?= $symbol ?>
        </label>
        <label class="limit">
            Fund
            <input type="number" name="index" value="0" min="0" max="255" size="4">
        </label>
        <p class="fine">
            Most readers need one fund, fund 0. A different number opens
            another, beside it. Your wallet pays the rent on the fund and the
            meter, about 0.004 SOL the first time, and a fee of 0.000005 SOL
            for each later change.
        </p>
        <?php /* Why the scan did not start, filled by `key.js`. Above the button,
                 because the button is what the reader is looking at. */ ?>
        <p class="refusal" data-setup-refusal role="alert" hidden>
            <strong>Nothing was sent to your wallet:</strong>
            <span data-setup-reason></span>.
        </p>
        <p><button type="submit" class="wallet">Set up the meter</button></p>
        <p class="fine">
            A wallet with no <?= $symbol ?> cannot deposit any.
            <a href="/faucet">The faucet</a> sends a little, once, to an
            address you paste.
        </p>
    </form>
<?= $view->render('setup-scan', ['symbol' => $symbol, 'development' => $meter['setup']['dev_wallet']]) ?>
    <noscript><p class="pending">Setting up a meter needs JavaScript: the key that the meter names is made and kept by this page's script.</p></noscript>

<?php elseif ($meter['stage'] === 'failed'): ?>
    <?php /* §8.2. Under the fund design SPL's `InsufficientFunds` means one
             thing: the fund holds less than the unpaid total. So the screen
             can say how much, and that the answer is money. */ ?>
    <h2>The charge did not go through</h2>
<?php $shortfall = $meter['result']?->shortfall; ?>
<?php if ($shortfall !== null && $shortfall > 0): ?>
    <p>
        Your fund is short by
        <?= View::e(Units::fromBaseUnits($shortfall, (int) $meter['decimals'])) ?>
        <?= $symbol ?> of what this settle would move. The fund holds
        <?= View::e((string) $meter['balance']) ?> <?= $symbol ?>.
    </p>
    <?php /* §8.2: the answer is money, so the screen offers the way to add
             it. One link, to where the scan is (`manage_meter`). */ ?>
    <p><a href="/meter#add">Add to the fund</a> from your wallet, with one scan, then open this article again.</p>
<?php endif ?>
<?php $said = Causes::describe($meter['result']?->cause); ?>
<?php if ($said !== null): ?>
    <p class="fine">The chain said: <code><?= View::e($said) ?></code></p>
<?php else: ?>
    <p class="fine"><?= View::e((string) ($meter['result']?->detail ?? '')) ?></p>
<?php endif ?>
    <p class="fine">
        Nothing was charged for this page. Transaction logs are not copied into
        this site's own logs (§8.1) — if you want to read them, they are yours,
        on the explorer.
    </p>

<?php elseif ($meter['stage'] === 'expired'): ?>
    <h2>Your meter has expired</h2>
    <p>
        It expired at <?= View::e((string) $onChain['expiry']) ?>, having used
        <?= View::e((string) $onChain['used']) ?> of
        <?= View::e((string) $onChain['limit']) ?> <?= $symbol ?>.
        Nothing can be metered on it until it is renewed.
    </p>

<?php elseif ($meter['stage'] === 'limit'): ?>
    <h2>You have reached your limit</h2>
    <p>
        Used <?= View::e((string) $onChain['used']) ?> of
        <?= View::e((string) $onChain['limit']) ?> <?= $symbol ?>,
        of which <?= View::e((string) $onChain['paid']) ?> has settled and
        <?= View::e((string) $onChain['unpaid']) ?> has not.
    </p>

<?php else: ?>
    <h2>The meter is running</h2>
    <p>
        Limit <?= View::e((string) $onChain['limit']) ?> <?= $symbol ?>
        · used <?= View::e((string) $onChain['used']) ?>
        · settled <?= View::e((string) $onChain['paid']) ?>
        · <?= View::e((string) $meter['items_remaining']) ?> views left.
    </p>
    <?php /* This branch is the panel's fallback and nothing reaches it through
             the article (2026-09-11). `meter.php` renders only where the body
             is withheld, and a reader whose meter is not blocked gets the
             body — so `metered` here needs a null `MeterResult`, which the
             POST always sets and the GET only reaches with a grant. The
             branch stays so that no stage can render blank, which
             `TemplateRenderTest` checks. */ ?>
<?php endif ?>

<?php /* §6 asks manage_meter to carry a permanent link from the meter, not
         only at the limit. Here it renders on every stage that holds a
         session, including `unreadable`, where the chain cannot be read. */ ?>
<?php $offered = $meter['stage'] === 'failed' && ($meter['result']?->shortfall ?? 0) > 0; ?>
<?php if (!$offered && ($onChain !== null || $meter['stage'] === 'unreadable')): ?>
    <p class="fine">
        <a href="/meter">The meter</a> — what you have spent, and where it stands,
        with the ways to renew it, add to its fund, or close it.
    </p>
<?php endif ?>
</section>
<?php if ($meter['provisioned'] && $meter['stage'] === 'anonymous'): ?>
<?php /* §12.2: one small module, where the key has work to do. */ ?>
<script type="module" src="/assets/key.js"></script>
<?php endif ?>
