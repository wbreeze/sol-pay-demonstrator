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
