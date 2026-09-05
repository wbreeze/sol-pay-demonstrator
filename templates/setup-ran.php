<?php
/**
 * @var list<\Newsprint\Setup\Step> $steps
 * @var bool $provisioned
 */
use Newsprint\Support\View;
?>
<article class="piece">
    <h1><?= $provisioned ? 'Provisioned' : 'Setup stopped' ?></h1>

<?php if ($provisioned): ?>
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

<?php if ($provisioned): ?>
    <p><a href="/">Go to the paper</a>.</p>
<?php else: ?>
    <form method="post" action="/setup">
        <button type="submit">Try again</button>
    </form>
<?php endif ?>
</article>
