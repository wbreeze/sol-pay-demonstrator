<?php
/**
 * One address in the panel: the alias, the address, and the two things §9 says
 * belong beside an address.
 *
 * **The explorer link is on the address itself** (2026-09-08). It was a second
 * control beside the copy button, and two controls on every one of a dozen
 * rows is a lot of furniture for a panel whose subject is the data. The
 * address is the thing you would click anyway. It is styled as data rather
 * than as a link — no colour, a hairline underline — because it is data, and
 * only takes the site's link colour on hover or focus.
 *
 * **The copy button stays, and gives the base58 and never the alias.** §9 is
 * explicit about that, and the reason is the one the preamble states: the
 * alias is this site's invention and means nothing to a wallet or an explorer,
 * so a reader who copies one and pastes it somewhere real gets a puzzle. The
 * button reads from a data attribute holding the address rather than from the
 * rendered text, so no future change to how the cell is laid out can quietly
 * make it copy the wrong thing. It also solves what linking the address
 * costs: dragging to select a link is awkward in most browsers, and the button
 * means nobody has to.
 *
 * **The link is omitted, not disabled, when the account is not there.** A
 * contract PDA before it is opened is a real address with nothing at it;
 * linking it would land a reader on "account not found" and teach them the
 * site is wrong. The address renders as plain text and the row's own words say
 * why.
 *
 * The derivation in `$value['derivation']` is deliberately NOT rendered here.
 * It belongs in the row's third cell, beside the value rather than inside it —
 * see `inspector.php`, which reads it off this same shape. Rendering it here
 * would put a sentence inside a cell whose whole job is to be a copyable
 * address.
 *
 * @var array{address: string, alias: ?string, explorer: bool, derivation: ?string} $value
 */
use Newsprint\Support\View;

$explorer = 'https://explorer.solana.com/address/'.rawurlencode($value['address']).'?cluster=devnet';
?>
<span class="addr"><?php if ($value['alias'] !== null): ?><span class="alias"><?= View::e($value['alias']) ?></span><?php endif ?><?php if ($value['explorer']): ?><a class="base58 explore" href="<?= View::e($explorer) ?>" rel="noreferrer noopener" target="_blank" title="Open this address on the Solana explorer"><?= View::e($value['address']) ?></a><?php else: ?><span class="base58"><?= View::e($value['address']) ?></span><?php endif ?><button type="button" class="copy" data-copy-address="<?= View::e($value['address']) ?>" aria-label="Copy the full address">copy</button></span>
