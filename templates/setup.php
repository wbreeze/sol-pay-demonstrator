<?php
/**
 * SPEC §12.0: first-run setup is `bin/setup`, and this page says so to
 * whoever opens an unprovisioned copy. It was the setup screen itself until
 * 2026-10-05. It has no button, so there is nothing here for a stranger on a
 * public host to press.
 *
 * @var bool $provisioned
 * @var array<string, int|string> $site
 */
use Newsprint\Support\View;
?>
<article class="piece">
    <h1>First run</h1>

<?php if ($provisioned): ?>
    <p class="lede">
        This copy of Newsprint is set up. Its mint, treasury and site account
        exist on devnet, and the inspector at the foot of this page names them.
    </p>
    <p><a href="/">Go to the paper</a>.</p>
<?php else: ?>
    <p class="lede">
        This copy of Newsprint has no mint, no treasury and no site account yet.
        Whoever runs it creates all three on devnet, once, with one command.
    </p>

    <section class="gate">
        <h2>Run setup</h2>
        <p>In the directory the site was installed in:</p>
        <p><code>bin/setup</code></p>
        <p class="pending">
            Then reload this page. Setup is a command and not a button so that
            no visitor can run it, and so that no web request makes a key.
        </p>
    </section>

    <h2>What it does</h2>
    <ol class="plan">
        <li>Generates the site authority and faucet keys, into <code>var/</code>, a directory that is not committed.</li>
        <li>Funds the authority from devnet's faucet, and moves a reserve to the faucet key.</li>
        <li>Creates the <?= View::e((string) $site['symbol']) ?> mint: <?= View::e((string) $site['decimals']) ?> decimals, mint authority the faucet key, no freeze authority.</li>
        <li>Creates the treasury token account, where settled payments land.</li>
        <li>Calls <code>initialize_site</code> with the page price, collection threshold and minimum limit.</li>
    </ol>
    <p class="pending">
        Devnet's faucet refuses more often than it works. When it does, the
        command names the address to fund and stops. Running it again resumes
        from there: every step asks the chain whether its work is already done.
    </p>
<?php endif ?>
</article>
