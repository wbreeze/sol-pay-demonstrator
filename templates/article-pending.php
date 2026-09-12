<?php
/**
 * The shell: an article for a reader the site could charge, before it has.
 *
 * `GET /a/{slug}` sends this without asking the chain anything (2026-09-11),
 * and `assets/read-on.js` posts the form at once and replaces this whole
 * `<article>` with the answer. The three to ten seconds a charge spends on the
 * validator used to pass with the *previous* page on screen and nothing to say
 * a link had been followed; they now pass here, with the lede to read.
 *
 * **Nothing on this page may be something a charge can change.** That is the
 * rule that lets the answer be swapped in rather than reloaded: the price, the
 * meter's numbers and the reader's accounts all come from the `Site` and
 * `Contract` accounts, so all of them arrive with the POST's answer, read by
 * the request that charged. What is here is what the content index knows.
 *
 * And it cannot say what the POST will do, because finding out is the read
 * this page exists to avoid: a reader with a running meter is charged, one
 * without is shown `set_meter`, one at the limit is refused. The sentence is
 * therefore about the asking, not the answer — the same discipline as the
 * advance's, which says only what is true when the form is sent.
 *
 * **Two lines, and the second is earned** (Gato's copy, 2026-09-11). The
 * first says what reading here is, and is true whatever the POST turns out to
 * do. The second appears only once the wait is longer than usual —
 * `metering.long_wait_ms`, which is measured, not guessed — so an ordinary
 * charge never shows it and a slow one gets something new to read rather than
 * the same line going stale. Both are marketing, deliberately: the reader is
 * waiting because they are paying the site directly, and that is the thing
 * worth saying while they wait. What the wait *was* is reported afterwards,
 * in the strip under the article and in the inspector.
 *
 * Without JavaScript the form is simply a button, and the POST answers with a
 * whole page.
 *
 * @var \Newsprint\Content\Piece $piece
 * @var int $longWaitMs  when the second line appears
 */
use Newsprint\Support\View;
?>
<article class="piece">
    <h1><?= View::e($piece->title) ?></h1>
    <p class="meta">
        <?= View::e((string) $piece->readingTime) ?> min
<?php if ($piece->isDraft()): ?>
        · <span class="draft">draft</span>
<?php endif ?>
    </p>

    <p class="lede"><?= View::e($piece->lede) ?></p>

    <form method="post" action="/a/<?= View::e(rawurlencode($piece->slug)) ?>" class="gate read-on" data-read-on>
        <h2>The rest is metered</h2>
        <?php /* Shown by assets/read-on.js as it sends the form. Hidden by
                 default, because without JavaScript nothing is under way
                 until the button is pressed. One live region, so the second
                 line is announced when it appears; the third is filled only if
                 the POST fails, and gives the button back. */ ?>
        <div class="read-on-status" data-read-on-status role="status" hidden>
            <p class="pending" data-read-on-now
              >Thank you for supporting.</p>
            <p class="pending" data-read-on-later
               data-after-ms="<?= View::e((string) $longWaitMs) ?>"
               hidden>No accounts, no-one reading over your shoulder.
              Another second…</p>
            <p class="pending" data-read-on-failed hidden></p>
        </div>
        <p data-read-on-manual>
            <button type="submit" class="secondary">Read on</button>
            <span class="fine">
                Meters this view against the limit you set, unless a grant you
                already hold covers it.
            </span>
        </p>
    </form>
    <script type="module" src="/assets/read-on.js"></script>
</article>
