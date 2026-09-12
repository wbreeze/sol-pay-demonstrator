<?php
/**
 * A piece's dates, for the meta line (SPEC §10.1).
 *
 * One partial rather than the same three lines in four templates, because the
 * rule about what shows is the interesting part and it should exist once:
 * `Piece::shownDate()` gives nothing for a draft, and the revision is dropped
 * when it falls on the day the piece was written, where it would say only that
 * the file was saved twice.
 *
 * @var \Newsprint\Content\Piece $piece
 * @var string $lead what separates these from whatever precedes them in the
 *                   line — '· ' inside an existing meta line, '' when they are
 *                   the whole of it
 */
use Newsprint\Support\View;

$shown = $piece->shownDate();
$revised = $piece->shownRevision();
?>
<?php if ($shown !== null): ?>
        <?= $lead ?><time datetime="<?= View::e($shown) ?>"><?= View::e(View::date($shown)) ?></time>
<?php if ($revised !== null): ?>
        · revised <time datetime="<?= View::e($revised) ?>"><?= View::e(View::date($revised)) ?></time>
<?php endif ?>
<?php endif ?>
