<?php
/**
 * What the metering step just did, under an article the reader has paid for.
 *
 * SPEC §2's claim 2 is that reading afterwards costs no interaction at all, so
 * this strip is deliberately not a control panel: it reports, links to the
 * transaction, and offers the one demo affordance §7.4 asks for.
 *
 * @var \Newsprint\Metering\MeterResult $result
 * @var array<string, mixed> $meter
 * @var \Newsprint\Content\Piece $piece
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Metering\MeterOutcome;
use Newsprint\Support\View;

$symbol = View::e((string) $meter['symbol']);
$contract = $meter['contract'];
?>
<section class="meter-strip">
<?php if (($meter['advanced'] ?? null) !== null): ?>
    <?php /* Shown once, from the redirect, and not stored — see the advance
             route for why a per-wallet log of these would be a claim on the
             privacy page.

             Every outcome is reported, including the ones that changed
             nothing. A settle that fails leaves the contract exactly as it
             was, so a button that stays silent looks like a button that did
             not work. */ ?>
<?php $advanced = $meter['advanced']; $solvency = $meter['solvency'] ?? null; ?>
<?php if ($advanced['outcome'] === MeterOutcome::Failed): ?>
    <p class="pending">
        <strong>The advance did not go through, and nothing was charged.</strong>
        The meter tried to move
        <?= View::e((string) ($solvency['would_move'] ?? '')) ?> <?= $symbol ?>
        — the residue you were carrying plus
        <?= View::e((string) $advanced['views']) ?> views — and the transfer
        was refused.
    </p>
<?php if (($solvency['balance_short'] ?? 0) > 0): ?>
    <p class="pending">
        Your balance is short by
        <?= View::e((string) $solvency['balance_short_demo']) ?> <?= $symbol ?>.
        This is §13.2's walkthrough rather than a fault: the faucet is stingy on
        purpose so that a depleted balance is reachable.
        <a href="/meter">The meter</a> shows where you stand.
    </p>
<?php elseif (($solvency['allowance_short'] ?? 0) > 0): ?>
    <p class="pending">
        The amount you approved no longer covers it — short by
        <?= View::e((string) $solvency['allowance_short_demo']) ?> <?= $symbol ?>.
        <a href="/meter">Renewing re-approves</a>.
    </p>
<?php endif ?>
<?php elseif ($advanced['outcome'] === MeterOutcome::Blocked): ?>
    <p class="pending">
        <strong>The advance would have passed your limit</strong>, so nothing
        was sent and nothing was charged — the meter refuses the whole call
        rather than metering part of it.
        <a href="/meter">Raise the limit, or close it</a>.
    </p>
<?php elseif ($advanced['outcome'] === MeterOutcome::Unreadable): ?>
    <p class="pending">The endpoint did not answer. Nothing was sent.</p>
<?php else: ?>
    <p>
        Advanced the meter <?= View::e((string) $advanced['views']) ?> views.
<?php if ($advanced['settled']): ?>
        <strong>That one settled</strong> — the unpaid balance crossed the
        collection threshold, so the transfer moved with it.
<?php else: ?>
        Nothing moved: still under the collection threshold.
<?php endif ?>
<?php if ($advanced['signature'] !== null): ?>
        <a href="https://explorer.solana.com/tx/<?= View::e((string) $advanced['signature']) ?>?cluster=devnet"
           rel="noreferrer noopener" target="_blank">That transaction</a>.
<?php endif ?>
    </p>
<?php endif ?>
<?php endif ?>

<?php /* Asked before the click, not only after it: `can_meter` is a limit
         check and cannot see a short balance, because the payment happens
         inside a CPI that SPL refuses. The button is still offered — watching
         it fail is a legitimate thing to want from a demo — but not silently.
         */ ?>
<?php if (($meter['solvency'] ?? null) !== null && !$meter['solvency']['clear'] && ($meter['advanced'] ?? null) === null): ?>
    <p class="pending">
<?php if ($meter['solvency']['balance_short'] > 0): ?>
        Heads up: the next advance would try to move
        <?= View::e((string) $meter['solvency']['would_move']) ?> <?= $symbol ?>
        and your balance is short by
        <?= View::e((string) $meter['solvency']['balance_short_demo']) ?>.
        It will be refused, and nothing will be charged.
<?php elseif (!$meter['solvency']['delegate_present']): ?>
        Heads up: this site is no longer a delegate on your token account, so a
        settle would be refused. <a href="/meter">Renewing re-approves</a>.
<?php else: ?>
        Heads up: the amount you approved is short by
        <?= View::e((string) $meter['solvency']['allowance_short_demo']) ?>
        <?= $symbol ?> of what the next advance would move.
        <a href="/meter">Renewing re-approves</a>.
<?php endif ?>
    </p>
<?php endif ?>
<?php if ($result->outcome === MeterOutcome::Granted): ?>
    <p>
        Served from a grant you already hold. <strong>The chain was not
        touched.</strong> One charge per article, not per request — a refresh,
        a back button and a prefetch are all this same page.
    </p>
<?php elseif ($result->outcome === MeterOutcome::Unconfirmed): ?>
    <p class="pending">
        Metered, but the confirmation did not arrive inside the window. You are
        reading it anyway and the site absorbed the risk — refusing would have
        risked charging you for nothing, which is the more expensive mistake
        (§7.3).
    </p>
<?php else: ?>
    <p>
        Metered <?= View::e((string) $meter['page_price']) ?> <?= $symbol ?> for this article.
<?php if ($result->settles): ?>
        <strong>This one settled</strong> — the unpaid balance crossed the
        collection threshold, so the transfer moved with it.
<?php else: ?>
        Nothing moved: the unpaid balance is still under the collection
        threshold, which is the point of having one.
<?php endif ?>
    </p>
<?php endif ?>

<?php if ($contract !== null): ?>
    <p class="fine">
        used <?= View::e((string) $contract['used']) ?>
        · settled <?= View::e((string) $contract['paid']) ?>
        · carried <?= View::e((string) $contract['unpaid']) ?>
        · <?= View::e((string) $meter['views_remaining']) ?> views left
        · <a href="/meter">the meter</a>
    </p>
<?php endif ?>

<?php if ($result->signature !== null): ?>
    <p class="fine">
        <a href="https://explorer.solana.com/tx/<?= View::e($result->signature) ?>?cluster=devnet"
           rel="noreferrer noopener" target="_blank">This transaction on the explorer</a>
    </p>
<?php endif ?>

    <?php /* §7.4. Labelled as a demo control, and it charges honestly: seven
             views is seven views, and the transfer that results is real. */ ?>
    <form method="post" action="/meter/advance" class="advance">
        <input type="hidden" name="slug" value="<?= View::e($piece->slug) ?>">
        <button type="submit" class="secondary">Advance the meter <?= View::e((string) $meter['step_views']) ?> views</button>
        <span class="fine">
            A demo control. It meters <?= View::e((string) $meter['step_views']) ?>
            views in one instruction and charges for them — below the
            ten-view threshold, so the settle fires on some clicks and not
            others.
        </span>
    </form>
</section>
