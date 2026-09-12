<?php
/**
 * SPEC §6.1. Two parts: a lede that is public, and a body that is not.
 *
 * The lede is not decoration. It gives the server something honest to render
 * to a visitor who has no contract, so the meter appears beside real content
 * instead of as a wall — and it is what makes claim 2 in §2 observable, since
 * the visitor sees the same page twice, once truncated and once whole, and the
 * only thing that changed was a contract account.
 *
 * @var \Newsprint\Content\Piece $piece
 * @var string|null $body       rendered HTML, or null when this reader may not have it
 * @var array<string, int|string> $site
 * @var array<string, mixed> $meter what the meter panel draws (SPEC §6, and
 *                                  sol-pay's `set_meter` / `authorize` states)
 * @var ?\Newsprint\Content\Piece $previous the older piece, when there is one
 * @var ?\Newsprint\Content\Piece $next     the newer one
 */
use Newsprint\Support\View;
?>
<article class="piece">
    <h1><?= View::e($piece->title) ?></h1>
    <p class="lede deck"><?= View::e($piece->lede) ?></p>

    <p class="meta">
        <?= View::e((string) $piece->readingTime) ?> min
        · <?= View::e((string) $site['page_price_demo']) ?> <?= View::e((string) $site['symbol']) ?>
<?= $view->render('piece-dates', ['piece' => $piece, 'lead' => '· ']) ?>
<?php if ($piece->isDraft()): ?>
        · <span class="draft">draft</span>
<?php endif ?>
    </p>

<?php if ($body !== null): ?>
    <div class="body">
<?= $body ?>
    </div>
<?php /* The onward links come after whatever the page is for — after the
         body here, after the meter below. Same rule, same place on the
         screen in both states, which is what §6.1 wants of a reader who sees
         the same page twice. Before the strip, because the strip is about the
         transaction rather than about the reading. */ ?>
<?= $view->render('piece-nav', ['previous' => $previous, 'next' => $next]) ?>
<?php /* What the metering step did, reported under the thing it paid for. */ ?>
<?= $view->render('meter-strip', ['result' => $meter['result'], 'meter' => $meter, 'piece' => $piece]) ?>
<?php else: ?>
    <?php /* The body is not on this page at all — not hidden, not delivered
             and covered. A reader who has not paid never receives it. */ ?>
<?= $view->render('meter', ['meter' => $meter, 'site' => $site]) ?>
<?php /* After the offer and not before it: a row of other articles between
         the lede and the meter would interrupt the one decision this page
         asks for. A reader who does not want this piece still has somewhere
         to go, which a site whose whole claim is that leaving costs nothing
         should not make hard. */ ?>
<?= $view->render('piece-nav', ['previous' => $previous, 'next' => $next]) ?>
<?php endif ?>
</article>
