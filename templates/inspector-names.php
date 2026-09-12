<?php
/**
 * The table of short names, first in the panel (SPEC §9).
 *
 * One row per value the panel shows: the short name, the value itself with the
 * explorer link on it and the copy button after it, and what the panel can say
 * about where the value came from. Everywhere else the value is written as its
 * short name, linked back to its row here.
 *
 * **The copy button is in the same cell as the address, after it**, rather
 * than in a column of its own: a column of `COPY` reads as a column of data
 * until you have looked at it twice, and the button belongs to the address
 * rather than to the row.
 *
 * The seeds in the provenance line are written in short names — `["contract",
 * SPDAmux, PAYRdol]` — and now they name rows in this same table, a few lines
 * up, instead of rows in whatever section happened to show that address.
 *
 * **The provenance sits under the address, not beside it.** A third column of
 * sentences is what made this panel 52rem wide while the article is 34rem, and
 * it was the only thing that did: gathered under the value, the whole panel
 * fits the article's measure, and the page stops being two widths.
 *
 * @var array{heading: string, names: list<array{value: string, alias: string, explorer: bool, note: ?string}>} $section
 */
use Newsprint\Support\View;
?>
        <section>
            <h3><?= View::e($section['heading']) ?></h3>
            <table class="names">
<?php foreach ($section['names'] as $name): ?>
<?php $explorer = 'https://explorer.solana.com/address/'.rawurlencode($name['value']).'?cluster=devnet'; ?>
                <tr id="<?= View::e(View::nameAnchor($name['value'])) ?>">
                    <th scope="row"><span class="alias"><?= View::e($name['alias']) ?></span></th>
                    <td class="value"><?php if ($name['explorer']): ?><a class="base58 explore" href="<?= View::e($explorer) ?>" rel="noreferrer noopener" target="_blank" title="Open this address on the Solana explorer"><?= View::e($name['value']) ?></a><?php else: ?><span class="base58"><?= View::e($name['value']) ?></span><?php endif ?><button type="button" class="copy" data-copy-address="<?= View::e($name['value']) ?>" aria-label="Copy the full value">copy</button><?php if ($name['note'] !== null): ?><span class="beneath"><?= View::e($name['note']) ?></span><?php endif ?></td>
                </tr>
<?php endforeach ?>
            </table>
        </section>
