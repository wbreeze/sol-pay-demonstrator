<?php
/**
 * The meter, on the article, where the decision to spend is actually made.
 *
 * This is `set_meter` from sol-pay's state diagram — "inform cost per fetch,
 * prompt limit amount, enforce minimum on limit amount" — and `authorize`
 * behind it. There is no sign-in screen: the diagram has no such node, and a
 * page whose only purpose is to collect an identity reads as identification
 * for tracking. Identifying happens here, one click before the money, where a
 * reader can see exactly what it is for.
 *
 * Two clicks and two wallet dialogs the first time, and the split is not
 * cosmetic. Each wallet call has to originate from a real user gesture, and
 * after the first `await` the second call is no longer inside one — Android
 * Chrome blocks the intent navigation on that basis (§6.3). One dialog per
 * click is also the honest description of what is happening: one proves who
 * you are, one authorizes a spending limit.
 *
 * @var array<string, mixed> $meter
 * @var array<string, int|string> $site
 */
use Newsprint\Support\View;

$symbol = View::e((string) $meter['symbol']);
$contract = $meter['contract'];
?>
<section class="gate meter" data-meter
         data-stage="<?= View::e((string) $meter['stage']) ?>"
         data-chain="<?= View::e((string) $meter['chain']) ?>"
         data-payer="<?= View::e((string) ($meter['wallet'] ?? '')) ?>"
         data-program="<?= View::e((string) $meter['program']) ?>"
         data-token-program="<?= View::e((string) $meter['token_program']) ?>">

<?php if (!$meter['provisioned']): ?>
    <h2>The rest is metered</h2>
    <p class="pending">This copy has not been set up yet. <a href="/setup">First run</a> creates the site on devnet.</p>

<?php elseif ($meter['stage'] === 'unreadable'): ?>
    <h2>The rest is metered</h2>
    <p class="pending">
        The chain could not be read just now, so the meter cannot say where you
        stand. The article is still here; nothing was charged.
    </p>

<?php elseif ($meter['stage'] === 'anonymous'): ?>
    <?php /* The diagram's `identified` choice, and its "viewer not identified"
             branch. One click, one wallet dialog, and no page. */ ?>
    <h2>The rest is metered</h2>
    <p>
        Reading on costs <?= View::e((string) $site['page_price_demo']) ?> <?= $symbol ?>
        an article, drawn from a limit you set yourself and can close at any time.
    </p>
    <p>
        Your wallet identifies you to this site and to nothing else. It is the
        only thing here that knows who you are, and
        <a href="/privacy">what that gets you</a> is one address and a session id.
    </p>
    <p>
        <button type="button" class="wallet" data-identify>Connect a wallet</button>
    </p>
    <div data-wallet-choices hidden></div>

<?php elseif ($meter['stage'] === 'unfunded'): ?>
    <?php /* §4.3. `approve_checked` against a token account that does not
             exist fails at the runtime, and the reader would never learn why,
             so the faucet is the branch before `set_meter` and not a screen
             they have to go and find. */ ?>
    <h2>You will need some <?= $symbol ?></h2>
    <p>
        <?= $symbol ?> is minted by this site, on devnet, and is worth nothing.
        It exists so the meter has something real to move.
    </p>
    <p>
        The faucet gives <?= View::e((string) $meter['faucet']['demo']) ?> <?= $symbol ?>
        and <?= View::e((string) $meter['faucet']['sol']) ?> SOL, once per wallet.
        The SOL is for the rent on your contract account and the fees; the site
        pays for both, and this one does not go through your wallet.
    </p>
<?php if ($meter['faucet']['available']): ?>
    <p><button type="button" class="wallet" data-faucet>Send me <?= View::e((string) $meter['faucet']['demo']) ?> <?= $symbol ?></button></p>
<?php else: ?>
    <p class="pending">
        This wallet has already had its one grant, and the balance is
        <?= View::e((string) $meter['balance']) ?> <?= $symbol ?>. That is
        §13.2's walkthrough rather than a fault — the faucet is stingy on
        purpose so a depleted balance is reachable.
    </p>
<?php endif ?>

<?php elseif ($meter['stage'] === 'set-meter'): ?>
    <?php /* `set_meter`: inform cost per fetch, prompt limit, enforce the
             minimum — all three before any wallet dialog opens. */ ?>
    <h2>Set a limit</h2>
    <p>
        Each article costs <?= View::e((string) $site['page_price_demo']) ?> <?= $symbol ?>.
        You authorize a ceiling; the site draws against it as you read, and
        settles in batches rather than per article.
    </p>
    <p class="fine">
        The limit is trust, not pacing: a site can draw straight to it whenever
        it likes. Better to meet that fact here, where the money is fake.
    </p>

    <p class="fine">
        Wallet <code><?= View::e(substr((string) $meter['wallet'], 0, 4).'…'.substr((string) $meter['wallet'], -4)) ?></code>
        · balance <?= View::e((string) $meter['balance']) ?> <?= $symbol ?>
    </p>

    <label class="limit">
        Limit
        <input type="text" inputmode="decimal" data-limit
               value="<?= View::e((string) $meter['limit_floor']) ?>"
               size="8">
        <?= $symbol ?>
    </label>
    <p class="fine" data-floor>
        The smallest limit you can set is
        <?= View::e((string) $meter['limit_floor']) ?> <?= $symbol ?>.
    </p>

    <p>
        <button type="button" class="wallet" data-authorize>Authorize</button>
    </p>

<?php elseif ($meter['stage'] === 'limit'): ?>
    <h2>You have reached your limit</h2>
    <p>
        Used <?= View::e((string) $contract['used']) ?> of
        <?= View::e((string) $contract['limit']) ?> <?= $symbol ?>,
        of which <?= View::e((string) $contract['paid']) ?> has settled and
        <?= View::e((string) $contract['unpaid']) ?> has not.
    </p>
    <p class="pending">
        Renewing and closing are <code>manage_meter</code>, which is the next
        rung. Closing forgives the unpaid residue and erases what this site
        holds about you.
    </p>

<?php else: ?>
    <h2>The meter is running</h2>
    <p>
        Limit <?= View::e((string) $contract['limit']) ?> <?= $symbol ?>
        · used <?= View::e((string) $contract['used']) ?>
        · settled <?= View::e((string) $contract['paid']) ?>
        · <?= View::e((string) $meter['views_remaining']) ?> views left.
    </p>
    <p class="pending">
        The body is not delivered yet: <code>meter_and_settle</code> is the
        next rung. Your contract is on chain and the arithmetic above is read
        from it.
    </p>
<?php endif ?>

    <p class="pending" data-meter-status hidden></p>
</section>

<?php if ($meter['provisioned'] && in_array($meter['stage'], ['anonymous', 'unfunded', 'set-meter'], true)): ?>
<?php /* §12.2: JavaScript where the wallet is, and nowhere else. Not loaded on
         a page with nothing for it to do. */ ?>
<script type="module" src="/assets/meter.js"></script>
<?php endif ?>
