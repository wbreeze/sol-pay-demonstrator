<?php
/**
 * SPEC §9. Present on every screen, collapsed by default, one click from any
 * page. Claim 7 in §2 is its job: every number on the screen came from an
 * account, not from the server's memory.
 *
 * A row is a label and a value, and optionally a third cell naming the
 * on-chain check the value mirrors — §9 asks for that beside every preflight
 * answer, and a number with no claim next to it cannot be wrong about
 * anything. Sections are homogeneous: every row in one either has the third
 * cell or none of them do.
 *
 * @var array<int, array{heading: string, rows: array<int, array{0: string, 1: string|array{address: string, alias: ?string, explorer: bool}, 2?: string}>, note?: string}> $sections
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Support\View;
?>
<details class="inspector">
    <summary>Inspector</summary>
    <div class="inspector-body">
        <p class="inspector-preamble">
            Short names like <code>SPDApep</code> are this site's own invention,
            derived from the address so they never change. They mean nothing to
            a wallet or an explorer. The full address is always beside them,
            and it is the full address that the copy button gives you — never
            the short name.
        </p>
<?php foreach ($sections as $section): ?>
        <section>
            <h3><?= View::e($section['heading']) ?></h3>
<?php
            /* A section either has a third column or it does not, and the
               table has to agree with itself either way: in a section that
               has one, a row without a third cell spans both — which is what
               lets a signature use the full width while the rows around it
               keep their mirrored check in a column of its own. */
            $mirrored = array_filter($section['rows'], static fn (array $r): bool => isset($r[2])) !== [];
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
<?php if (isset($row[2])): ?>
                    <td><?= $cell ?></td>
                    <td class="mirrors"><?= View::e($row[2]) ?></td>
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
    </div>
    <?php /* Two jobs: the copy buttons on every address row, and — on the
             pages that have one — the deferred read of the transaction's
             event. It used to load only for the second, which stopped being
             true when addresses gained controls. Local file, per §10.3. */ ?>
    <script type="module" src="/assets/inspector.js"></script>
</details>
