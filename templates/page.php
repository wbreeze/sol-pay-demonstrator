<?php
/**
 * An unmetered page — the privacy page (§10.2), and anything else the site
 * owes a reader before they have decided anything.
 *
 * @var \Newsprint\Content\Piece $piece
 * @var string $body
 */
use Newsprint\Support\View;
?>
<article class="piece">
    <?php /* The heading is the template's, here as on the article, and the
             markdown carries none: a piece has exactly one title and it is
             written once, in the front matter. `bin/build-content` refuses a
             body that supplies a second one. */ ?>
    <h1><?= View::e($piece->title) ?></h1>
<?php if ($piece->lede !== ''): ?>
<?php /* A lede, where the piece has one (2026-09-14).

         `bin/build-content` requires a lede of a metered piece and of nothing
         else, because of what it is *for* on that side: §6.1 needs something
         honest to render beside the meter to a visitor with no contract. A
         public page is served whole, so nothing has to stand in for it — which
         is why `privacy.md` has none and this branch is not taken there.

         But a piece that has written one has written a standfirst, and
         throwing it away because the piece is free would be a shape decision
         overruling an editorial one. It sets as the deck, exactly as it does
         above an article's body. */ ?>
    <p class="lede deck"><?= View::e($piece->lede) ?></p>
<?php endif ?>
<?php /* §10.2's page is a list of what this site holds, and a list like that
         is a weaker claim undated. No price and no reading time: a public page
         is not for sale, and the reading time belongs beside a price. */ ?>
<?php if ($piece->shownDate() !== null): ?>
    <p class="meta">
<?= $view->render('piece-dates', ['piece' => $piece, 'lead' => '']) ?>
    </p>
<?php endif ?>
    <div class="body">
<?= $body ?>
    </div>
</article>
