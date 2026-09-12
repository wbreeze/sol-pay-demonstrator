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
    <div class="body">
<?= $body ?>
    </div>
</article>
