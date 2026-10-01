<?php
/**
 * `manage_meter` (§6): what the meter has spent, and where it stands.
 *
 * Reachable at any time, decided 2026-09-02, because a reader who has let a
 * site draw on their fund may reasonably expect to find, at any moment, a page
 * that says what they have spent and offers a way out.
 *
 * **The way out is being rebuilt for the fund design**, and this page says so
 * rather than offering controls that no longer work. Closing the meter with
 * the browser key arrives first (SPEC §5.4), and with it §10.4's disclosures,
 * which belong in front of that button. Renewing by a scan follows, with the
 * setup it shares (SPEC §6.3).
 *
 * @var string $stage
 * @var array<string, int|string> $site
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Support\View;

$symbol = View::e((string) ($symbol ?? $site['symbol']));
?>
<article class="piece">
    <h1>The meter</h1>

<?php if ($stage === 'anonymous'): ?>
    <p class="lede">
        This browser holds no meter for this site, so there is nothing here to
        manage.
    </p>
<?php if ($ended ?? false): ?>
    <p class="pending">
        The meter this browser held has been closed, or renewed from another
        device, which then holds it. This browser's session with it has ended.
    </p>
<?php endif ?>
    <p><a href="/">Go and read something</a>.</p>

<?php elseif ($stage === 'unreadable'): ?>
    <p class="lede">The chain could not be read just now, so this page cannot say where you stand.</p>
    <p class="pending">Nothing was charged and nothing was changed. Try again in a moment.</p>

<?php else: ?>
    <section class="gate meter">
        <p class="fine">
            Meter <code><?= View::e(substr((string) $meter['address'], 0, 4).'…'.substr((string) $meter['address'], -4)) ?></code>
        </p>

        <table class="status">
            <tr><th scope="row">limit</th><td><?= View::e((string) $meter['limit']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">used</th><td><?= View::e((string) $meter['used']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">settled</th><td><?= View::e((string) $meter['paid']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">unpaid</th><td><?= View::e((string) $meter['unpaid']) ?> <?= $symbol ?> — carried, not owed until it settles</td></tr>
            <tr><th scope="row">expires</th><td><?= View::e((string) $meter['expiry']) ?></td></tr>
            <tr><th scope="row">fund balance</th><td><?= View::e((string) $balance) ?> <?= $symbol ?></td></tr>
<?php if ($items_remaining !== null): ?>
            <tr><th scope="row">views left</th><td><?= View::e((string) $items_remaining) ?></td></tr>
<?php endif ?>
        </table>

<?php if ($blocked !== null): ?>
        <p class="pending">
            The meter is blocked: <code><?= View::e((string) $blocked) ?></code>.
        </p>
<?php endif ?>

        <p class="pending">
            Renewing and closing the meter are being rebuilt for the fund
            design, and are not offered here yet.
        </p>
    </section>
<?php endif ?>

    <p class="fine"><a href="/">Back to the paper</a> · <a href="/privacy">What this site holds about you</a></p>
</article>
