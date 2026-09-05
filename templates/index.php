<?php
/**
 * @var list<\Newsprint\Content\Piece> $articles
 * @var array<string, int|string> $site
 */
use Newsprint\Support\View;
?>
<article class="standfirst">
    <h1>A newspaper that charges by the article, and keeps no reading list</h1>
    <p>
        Every piece here costs <strong><?= View::e((string) $site['page_price_demo']) ?> <?= View::e((string) $site['symbol']) ?></strong>
        to read. You authorise a limit once, in your wallet, and after that
        reading costs no interaction at all — no popup, no confirmation, no
        second signature. The site draws against the limit you set and stops
        at it.
    </p>
    <p>
        What the site does not do is remember what you read. A paper newspaper
        never knew which pages you lingered on; neither does this. The
        <a href="/privacy">whole list</a> of what it holds fits on a screen,
        which is the point of printing it in full.
    </p>
    <p>
        You can watch the payment happen, too. The inspector at the foot of
        every page shows the accounts the page was rendered from.
    </p>
</article>

<?php if ($articles === []): ?>
<p class="empty">Nothing built yet. Run <code>bin/build-content</code>.</p>
<?php else: ?>
<ol class="pieces">
<?php foreach ($articles as $piece): ?>
    <li>
        <h2><a href="/a/<?= View::e($piece->slug) ?>"><?= View::e($piece->title) ?></a></h2>
        <p class="lede"><?= View::e($piece->lede) ?></p>
        <p class="meta">
            <?= View::e((string) $piece->readingTime) ?> min
            · <?= View::e((string) $site['page_price_demo']) ?> <?= View::e((string) $site['symbol']) ?>
<?php if ($piece->isDraft()): ?>
            · <span class="draft">draft</span>
<?php endif ?>
        </p>
    </li>
<?php endforeach ?>
</ol>
<?php endif ?>
