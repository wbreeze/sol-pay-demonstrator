<?php
/**
 * @var list<\Newsprint\Setup\Step> $steps
 * @var bool $provisioned
 * @var bool $refused whether the request was turned away before setup ran at
 *                    all ({@see \Newsprint\Setup\SameOrigin}), which needs
 *                    its own lede: *did not finish* and *did not start* are
 *                    different things to have happened to an operator.
 */
use Newsprint\Support\View;
?>
<article class="piece">
    <h1><?= !$refused && $provisioned ? 'Provisioned' : 'Setup stopped' ?></h1>

<?php if ($refused): ?>
    <p class="lede">
        Setup did not run. The request did not come from a page on this site,
        so nothing was created, nothing was signed and nothing was spent.
    </p>
<?php elseif ($provisioned): ?>
    <p class="lede">
        The mint, the treasury and the site account exist. The addresses are in
        <code>var/site.json</code> and in the inspector at the foot of this page.
    </p>
<?php else: ?>
    <p class="lede">
        Setup did not finish. Nothing it managed to create is lost — running it
        again resumes from here rather than starting over.
    </p>
<?php endif ?>

    <ol class="steps">
<?php foreach ($steps as $step): ?>
        <li class="step step-<?= View::e($step->status) ?>">
            <h2><?= View::e($step->name) ?> <span class="badge"><?= View::e($step->status) ?></span></h2>
            <p><?= View::e($step->detail) ?></p>
<?php if ($step->address !== null): ?>
            <p class="addr"><code><?= View::e($step->address) ?></code></p>
<?php endif ?>
<?php if ($step->signature !== null): ?>
            <p class="addr">
                <a href="https://explorer.solana.com/tx/<?= View::e($step->signature) ?>?cluster=devnet"><?= View::e(substr($step->signature, 0, 12)) ?>…</a>
            </p>
<?php endif ?>
        </li>
<?php endforeach ?>
    </ol>

<?php if (!$refused && $provisioned): ?>
    <p><a href="/">Go to the paper</a>.</p>
<?php else: ?>
<?php /* The button works from here even after a refusal, because pressing it
         on this page is a same-origin POST — which is the whole of the
         distinction being drawn. */ ?>
    <form method="post" action="/setup">
        <button type="submit">Try again</button>
    </form>
<?php endif ?>
</article>
