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
 */
use Newsprint\Support\View;
?>
<article class="piece">
    <h1><?= View::e($piece->title) ?></h1>
    <p class="meta">
        <?= View::e((string) $piece->readingTime) ?> min
        · <?= View::e((string) $site['page_price_demo']) ?> <?= View::e((string) $site['symbol']) ?>
<?php if ($piece->isDraft()): ?>
        · <span class="draft">draft</span>
<?php endif ?>
    </p>

    <p class="lede"><?= View::e($piece->lede) ?></p>

<?php if ($body !== null): ?>
    <div class="body">
<?= $body ?>
    </div>
<?php else: ?>
    <?php /* The body is not on this page at all — not hidden, not delivered
             and covered. A reader who has not paid never receives it. */ ?>
<?= $view->render('meter', ['meter' => $meter, 'site' => $site]) ?>
<?php endif ?>
</article>
