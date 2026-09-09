<?php
/**
 * The panel's sections, extracted 2026-09-09 so two callers render exactly the
 * same markup: `inspector.php`, when this request already had the site state
 * in hand, and `GET /inspector/panel`, when it did not and a reader has asked
 * for it by opening the panel.
 *
 * One partial rather than two copies. The alternative is a fragment that
 * drifts from the inline version, and a reader who sees a different panel
 * depending on which page they opened it from.
 *
 * @var array<int, array{heading: string, rows: array<int, array{0: string, 1: string|array{address: string, alias: ?string, explorer: bool, derivation: ?string}, 2?: string}>, note?: string}> $sections
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Support\View;
?>
<?php foreach ($sections as $section): ?>
        <section>
            <h3><?= View::e($section['heading']) ?></h3>
<?php
            /* A section either has a third column or it does not, and the
               table has to agree with itself either way: in a section that
               has one, a row without a third cell spans both — which is what
               lets a signature use the full width while the rows around it
               keep their mirrored check in a column of its own.

               An address carries its own third cell, so the question is asked
               of both sources at once. Otherwise a section of nothing but
               addresses would decide it had no third column and then render
               one on every row. */
            $third = static fn (array $r): ?string => $r[2]
                ?? (is_array($r[1]) ? ($r[1]['derivation'] ?? null) : null);
            $mirrored = array_filter($section['rows'], static fn (array $r): bool => $third($r) !== null) !== [];
?>
            <table<?= $mirrored ? ' class="mirrored"' : '' ?>>
<?php foreach ($section['rows'] as $row): ?>
<?php
    /* An address row and a plain row differ only in what goes inside the value
       cell, so the colspan logic above stays one branch rather than four. */
    $cell = is_array($row[1])
        ? $view->render('inspector-address', ['value' => $row[1]])
        : View::e($row[1]);
?>
                <tr>
                    <th scope="row"><?= View::e($row[0]) ?></th>
<?php if (($claim = $third($row)) !== null): ?>
                    <td><?= $cell ?></td>
                    <td class="mirrors"><?= View::e($claim) ?></td>
<?php elseif ($mirrored): ?>
                    <td colspan="2"><?= $cell ?></td>
<?php else: ?>
                    <td><?= $cell ?></td>
<?php endif ?>
                </tr>
<?php endforeach ?>
<?php if (isset($section['event'])):
    /* Filled by assets/inspector.js when the panel is first opened. Deferred
       rather than read on the request that made the transaction, because §12.4
       budgets about three RPC calls per metered view and §9 says this panel is
       collapsed by default — so the fourth call is only spent by a reader who
       actually looks. Without JavaScript the row keeps the sentence below,
       which stays true rather than becoming a spinner that never resolves. */ ?>
                <tr data-event-for="<?= View::e($section['event']) ?>">
                    <th scope="row">event</th>
                    <td data-event-slot>not read yet — the panel reads it from the chain when you open this</td>
                </tr>
<?php endif ?>
            </table>
<?php if (isset($section['link'])): ?>
            <p class="section-link">
                <a href="<?= View::e($section['link']['href']) ?>"
                   rel="noreferrer noopener" target="_blank"><?= View::e($section['link']['text']) ?></a>
            </p>
<?php endif ?>
<?php if (isset($section['note'])): ?>
            <p class="note"><?= View::e($section['note']) ?></p>
<?php endif ?>
        </section>
<?php endforeach ?>
