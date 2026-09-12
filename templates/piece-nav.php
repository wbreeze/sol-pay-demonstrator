<?php
/**
 * Where to go from the end of an article.
 *
 * It comes after whatever the page is for: under the body for a reader who
 * has paid, under the meter for one who has not, which is the same place on
 * the screen in both states. **Previous is the older piece**: the index runs
 * newest first, so these two links run against it, which is how the words
 * read to a reader and not how the list is ordered.
 *
 * Nothing here is metered by being linked. §7.1 is the reason it can be this
 * plain: a GET never charges, so a link, a hover, a prefetch and a middle
 * click all cost a reader nothing until they ask to read.
 *
 * @var ?\Newsprint\Content\Piece $previous
 * @var ?\Newsprint\Content\Piece $next
 */
use Newsprint\Support\View;
?>
<?php if ($previous !== null || $next !== null): ?>
<nav class="piece-nav" aria-label="Other articles">
<?php if ($previous !== null): ?>
    <a class="previous" rel="prev" href="/a/<?= View::e(rawurlencode($previous->slug)) ?>">
        <span class="where">Previous</span>
        <span class="what"><?= View::e($previous->title) ?></span>
    </a>
<?php endif ?>
<?php if ($next !== null): ?>
    <a class="next" rel="next" href="/a/<?= View::e(rawurlencode($next->slug)) ?>">
        <span class="where">Next</span>
        <span class="what"><?= View::e($next->title) ?></span>
    </a>
<?php endif ?>
</nav>
<?php endif ?>
