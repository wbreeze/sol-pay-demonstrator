<?php
/**
 * A value that lives in the table of short names: here, only its short name.
 *
 * Until 2026-09-12 this rendered the alias, the full base58, an explorer link
 * and a copy button, at every sighting — a dozen times down one panel, three
 * of them the same address. What a reader is doing in most of those rows is
 * *matching*: is the account this instruction signs with the same one the site
 * account calls its authority? A 44-character string answers that badly and a
 * short name answers it at a glance, which is what §9 introduced them for.
 *
 * The link goes to the row in the table where the base58, the explorer link
 * and the copy button now live, once. It is the definition of the word, and a
 * reader who wants the address is one click from it — rather than one scroll
 * and a comparison of strings by eye.
 *
 * **The copy button does not follow it here.** §9 is explicit that copying
 * gives the base58 and never the alias, and the surest way to keep that true
 * is for there to be exactly one copy button per address, beside the address
 * itself.
 *
 * @var array{value: string, alias: string, explorer: bool, note: ?string} $value
 */
use Newsprint\Support\View;
?>
<a class="shorthand" href="#<?= View::e(View::nameAnchor($value['value'])) ?>"><?= View::e($value['alias']) ?></a>
