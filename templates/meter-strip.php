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
use Newsprint\Metering\ChargeState;
use Newsprint\Metering\MeterOutcome;
use Newsprint\Support\View;

$symbol = View::e((string) $meter['symbol']);
$contract = $meter['contract'];
$advanced = $meter['advanced'] ?? null;
$solvency = $meter['solvency'] ?? null;

/*
 * Whether the report above has already accounted for the shortfall below.
 *
 * Only a *failed* advance does: its branches name the same constraints the
 * heads-up names, in the same terms, so rendering both would say one thing
 * twice. Every other outcome — including a settle that went through and left
 * the reader short — has said nothing about solvency, and suppressing the
 * warning there was the defect this replaces. The old test was
 * `advanced === null`, which is "has anything happened", not "has it been
 * said".
 */
$reported = $advanced !== null && $advanced['outcome'] === MeterOutcome::Failed;

/*
 * The charge this article was served on has not confirmed (§7.3,
 * 2026-09-17). Every account figure on this request was read before it could
 * have landed, so the strip shows none of them — not the meter's line, not
 * the heads-up worked out from them, and not the advance, whose warning is
 * made of them. The page's follow-up brings them back with a read that
 * comes after the answer.
 */
$awaiting = $result->awaiting();
?>
<section class="meter-strip">
<?php if ($advanced !== null): ?>
    <?php /* Shown once, from the redirect, and not stored — see the advance
             route for why a per-wallet log of these would be a claim on the
             privacy page.

             Every outcome is reported, including the ones that changed
             nothing. A settle that fails leaves the contract exactly as it
             was, so a button that stays silent looks like a button that did
             not work. */ ?>
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
<?php elseif (($solvency['delegate_is_ours'] ?? true) === false): ?>
    <?php /* Checked before the allowance rather than after it. SPL clears the
             delegate the moment the approved amount is spent to zero, so a
             revoked account usually reports `allowance_short > 0` as well —
             and "the amount you approved no longer covers it" is the wrong
             account of an approval that is *gone*. Checked last, it was also
             checked never: a revoke that left `delegated_amount` standing
             produced no paragraph at all, which is how this was found.

             The account holds one delegate, so there are two ways to arrive
             here and they read differently to a reader: the field is empty, or
             it names somebody else. */ ?>
<?php if (($meter['delegate'] ?? null) === null): ?>
    <p class="pending">
        This site is no longer a delegate on your token account, so the
        transfer was refused — the approval was revoked, or SPL cleared it
        when the approved amount reached zero.
        <a href="/meter">Renewing re-approves</a>.
    </p>
<?php else: ?>
    <p class="pending">
        Another site's approval has replaced this one on your token account, so
        the transfer was refused. A token account holds one delegate at a time.
        <a href="/meter">Renewing re-approves</a> here, and takes that site's
        permission away in turn.
    </p>
<?php endif ?>
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

<?php if ($result->asksAgain()): ?>
    <?php /* `assets/charge.js` sends the follow-up named here as soon as it
             runs, and the answer replaces this page. The words claim only
             what is true now: the charge went out and the network has not
             answered. Without JavaScript, a reload asks on the way. */ ?>
    <div class="pending" role="status" data-charge-pending="/a/<?= View::e(rawurlencode($piece->slug)) ?>/confirm">
<?php if ($result->outcome === MeterOutcome::Sent): ?>
        <p>
            Charging <?= View::e((string) $meter['page_price']) ?> <?= $symbol ?> for this article.
            The network is still confirming it; your meter will show here in a moment.
        </p>
<?php else: ?>
        <p>
            You hold this article. The network is still confirming its
            charge; your meter will show here once it has.
        </p>
<?php endif ?>
        <?php /* A link rather than "reload": the page may be the answer to a
                 POST, and reloading that resubmits the form. The GET asks
                 the chain once on the way. The meter is the way out while
                 the figures are withheld — the line that usually carries its
                 link is one of them. */ ?>
        <noscript><p class="fine"><a href="/a/<?= View::e(rawurlencode($piece->slug)) ?>">Open the article again</a> to see it.</p></noscript>
        <p class="fine"><a href="/meter">The meter</a></p>
        <p class="fine" data-charge-failed hidden></p>
    </div>
    <script type="module" src="/assets/charge.js"></script>
<?php elseif ($result->outcome === MeterOutcome::Granted): ?>
    <p>
<?php if ($result->earlier): ?>
        Served from a grant you already hold. The site asked the chain one
        thing — whether the charge for it had landed — and charged nothing.
<?php else: ?>
        Served from a grant you already hold. <strong>The chain was not
        touched.</strong>
<?php endif ?>
        One charge per article, not per request — a refresh,
        a back button and a prefetch are all this same page.
<?php if ($result->chargeState === ChargeState::Refused): ?>
        The network turned its charge down after the article reached you, so
        it cost you nothing.
<?php elseif ($result->chargeState === ChargeState::Unknown): ?>
        Its charge never reached the network, so it cost you nothing.
<?php endif ?>
    </p>
<?php elseif ($result->outcome === MeterOutcome::Absorbed): ?>
    <?php /* §7.3's choice, told plainly: the reader keeps the article and the
             site keeps the loss. The heads-up below names what was short,
             when something was — it is worked out from a read taken after
             the refusal. */ ?>
    <p class="pending">
        <strong>The network turned this charge down</strong> after the article
        was already on its way to you, so nothing was charged. The article is
        yours anyway — the site takes that loss rather than taking it back.
    </p>
<?php elseif ($result->outcome === MeterOutcome::Unconfirmed && $result->chargeState === ChargeState::Unknown): ?>
    <p class="pending">
        The charge for this article never reached the network, so nothing was
        charged. The article is yours anyway.
    </p>
<?php elseif ($result->outcome === MeterOutcome::Unconfirmed): ?>
    <p class="pending">
        Metered, but the confirmation did not arrive inside the window. You are
        reading it anyway and the site absorbed the risk — refusing would have
        risked charging you for nothing, which is the more expensive mistake
        (§7.3). Reload later to see whether it landed.
    </p>
<?php else: ?>
    <p>
        Metered <?= View::e((string) $meter['page_price']) ?> <?= $symbol ?> for this article.
<?php if ($result->settles === true): ?>
        <strong>This one settled</strong> — the unpaid balance crossed the
        collection threshold, so the transfer moved with it.
<?php elseif ($result->settles === false): ?>
        Nothing moved: the unpaid balance is still under the collection
        threshold, which is the point of having one.
<?php endif ?>
    </p>
<?php endif ?>

<?php if ($contract !== null && !$awaiting): ?>
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
           rel="noreferrer noopener" target="_blank">This transaction on chain</a>
    </p>
<?php endif ?>

<?php /* Asked before the click, not only after it: `can_meter` is a limit
         check and cannot see a short balance, because the payment happens
         inside a CPI that SPL refuses. The button is still offered — watching
         it fail is a legitimate thing to want from a demo — but not silently.
         **And after the click too, unless the click already said it.** The
         gate used to be `advanced === null`, so one successful advance
         silenced the warning for the rest of the reader's visit — including
         the advance that spends the balance down and leaves the *next* one
         certain to fail. That is the case the warning exists for, and it was
         the one case that did not get it. See `$reported` at the top. */ ?>
<?php if ($solvency !== null && !$solvency['clear'] && !$reported && !$awaiting): ?>
    <p class="pending">
<?php if ($solvency['balance_short'] > 0): ?>
        Heads up: the next advance would try to move
        <?= View::e((string) $solvency['would_move']) ?> <?= $symbol ?>
        and your balance is short by
        <?= View::e((string) $solvency['balance_short_demo']) ?>.
        It will be refused, and nothing will be charged.
<?php elseif (!($solvency['delegate_is_ours'] ?? true)): ?>
<?php if (($meter['delegate'] ?? null) === null): ?>
        Heads up: this site is no longer a delegate on your token account, so a
        settle would be refused. <a href="/meter">Renewing re-approves</a>.
<?php else: ?>
        Heads up: another site's approval has replaced this one on your token
        account, so a settle would be refused.
        <a href="/meter">Renewing re-approves</a> here, and takes that site's
        permission away in turn.
<?php endif ?>
<?php else: ?>
        Heads up: the amount you approved is short by
        <?= View::e((string) $solvency['allowance_short_demo']) ?>
        <?= $symbol ?> of what the next advance would move.
        <a href="/meter">Renewing re-approves</a>.
<?php endif ?>
    </p>
<?php endif ?>

    <?php /* §7.4. Labelled as a demo control, and it charges honestly: seven
             views is seven views, and the transfer that results is real.
             Not offered while the article's own charge is out: its warning
             is made of figures this request could not read, and it waits
             for the confirmation the page is already waiting for. */ ?>
<?php if (!$awaiting): ?>
    <form method="post" action="/meter/advance" class="advance" data-advance>
        <input type="hidden" name="slug" value="<?= View::e($piece->slug) ?>">
        <button type="submit" class="secondary">Advance the meter <?= View::e((string) $meter['step_views']) ?> views</button>
        <span class="fine">
            A demo control. It meters <?= View::e((string) $meter['step_views']) ?>
            views in one instruction and charges for them — below the
            ten-view threshold, so the settle fires on some clicks and not
            others.
        </span>
        <?php /* Shown by assets/advance.js once the form is sent, because the
                 advance waits on a validator and the old page stays on screen
                 until it answers. It claims only what is true at that moment;
                 the page the redirect lands on reports the outcome. Hidden
                 without JavaScript, where the form works exactly the same. */ ?>
        <p class="pending" data-advance-status role="status" hidden>
            Advancing the meter <?= View::e((string) $meter['step_views']) ?> views…
        </p>
        <?php /* Filled by the script only if the POST does not arrive — a
                 reason it composes, since only it knows one. Empty and hidden
                 here for the same reason the sentence above is hidden: without
                 JavaScript nothing has been sent. */ ?>
        <p class="pending" data-advance-failed role="status" hidden></p>
    </form>
<?php endif ?>
    <script type="module" src="/assets/advance.js"></script>
</section>
