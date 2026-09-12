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
 * @var array<int, array{heading: string, rows?: array<int, array{0: string, 1: string|array{value: string, alias: string, explorer: bool, note: ?string}, 2?: string}>, names?: array<int, array{value: string, alias: string, explorer: bool, note: ?string}>, note?: string}> $sections
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Support\View;
?>
<?php foreach ($sections as $section): ?>
<?php if (isset($section['names'])): ?>
<?= $view->render('inspector-names', ['section' => $section]) ?>
<?php continue; endif ?>
        <section>
            <h3><?= View::e($section['heading']) ?></h3>
<?php
            /* A section either has a third column or it does not, and the
               table has to agree with itself either way: in a section that
               has one, a row without a third cell spans both — which is what
               lets a signature use the full width while the rows around it
               keep their mirrored check in a column of its own.

               Only the row's own third cell now. Until 2026-09-12 an address
               offered its derivation here as a fallback, which meant an
               account row in the last transaction had two things to say in one
               cell and the flags won — so the provenance §9 asks for beside
               every address was quietly missing from the section with the most
               addresses in it. It is in the table of short names instead,
               exactly once per address. */
            $third = static fn (array $r): ?string => $r[2] ?? null;

            /* Where the third cell goes, said by the section rather than
               guessed from the text (2026-09-12). A sentence goes *under* the
               value it is about, which is what lets the whole panel be the
               article's width instead of 52rem — the two sections carrying
               sentences were the only ones that needed the extra 18rem, and
               moving their claims under cost seventeen pixels of height.
               `signer, writable` stays beside the value: two words read as a
               suffix to it, where a sentence would read as a caption. */
            $under = ($section['claims'] ?? 'beside') === 'under';
            $mirrored = !$under && array_filter($section['rows'], static fn (array $r): bool => $third($r) !== null) !== [];
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
<?php if ($under && ($claim = $third($row)) !== null): ?>
                    <td><?= $cell ?><span class="beneath"><?= View::e($claim) ?></span></td>
<?php elseif (($claim = $third($row)) !== null): ?>
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
                    <?php /* Spanning, like the signature above it and for the
                             same reason: the decoded event is a sentence and
                             there is no check beside it to mirror. It used to
                             stop at the value column and wrap inside a third
                             of the width. */ ?>
                    <td data-event-slot<?= $mirrored ? ' colspan="2"' : '' ?>>not read yet — the panel reads it from the chain when you open this</td>
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
