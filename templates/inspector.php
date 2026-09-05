<?php
/**
 * SPEC §9. Present on every screen, collapsed by default, one click from any
 * page. Claim 7 in §2 is its job: every number on the screen came from an
 * account, not from the server's memory.
 *
 * At this stage it can only show what the site was configured with. The
 * decoded accounts, the preflight answers and the last transaction arrive as
 * the screens that read them do.
 *
 * @var array<int, array{heading: string, rows: array<int, array{0: string, 1: string}>, note?: string}> $sections
 */
use Newsprint\Support\View;
?>
<details class="inspector">
    <summary>Inspector</summary>
    <div class="inspector-body">
        <p class="inspector-preamble">
            Short names like <code>SPDApep</code> are this site's own invention,
            derived from the address so they never change. They mean nothing to
            a wallet or an explorer. The full address is always beside them.
        </p>
<?php foreach ($sections as $section): ?>
        <section>
            <h3><?= View::e($section['heading']) ?></h3>
            <table>
<?php foreach ($section['rows'] as [$label, $value]): ?>
                <tr>
                    <th scope="row"><?= View::e($label) ?></th>
                    <td><?= View::e($value) ?></td>
                </tr>
<?php endforeach ?>
            </table>
<?php if (isset($section['note'])): ?>
            <p class="note"><?= View::e($section['note']) ?></p>
<?php endif ?>
        </section>
<?php endforeach ?>
    </div>
</details>
