<?php
/**
 * SPEC §12.0: first-run setup is a page, not a command. Every call it makes is
 * available over JSON-RPC, including the airdrop, so the prerequisite stays one
 * language runtime — and the operator never handles a keypair.
 *
 * @var array<string, mixed> $status
 * @var array<string, int|string> $site
 * @var array<string, int> $setup
 * @var string|null $error
 */
use Newsprint\Support\View;

$sol = static fn (int $lamports): string => rtrim(rtrim(number_format($lamports / 1_000_000_000, 9, '.', ''), '0'), '.');
?>
<article class="piece">
    <h1>First run</h1>
    <p class="lede">
        This copy of Newsprint has no mint, no treasury and no site account yet.
        Setup creates all three on devnet, once, and writes the addresses down.
    </p>

<?php if ($error !== null): ?>
    <section class="gate">
        <h2>The endpoint did not answer</h2>
        <p><code><?= View::e($error) ?></code></p>
        <p class="pending">Setup needs the RPC endpoint to read balances. Nothing has been created.</p>
    </section>
<?php elseif ($status['provisioned']): ?>
    <section class="gate">
        <h2>Already provisioned</h2>
        <p>
            This site exists on chain. Setup will not run a second time — it is
            the one thing here that is irreversible on a given deployment.
        </p>
        <p><a href="/">Go to the paper</a>.</p>
    </section>
<?php else: ?>
    <h2>What it will do</h2>
    <ol class="plan">
        <li>Generate the site authority and faucet keys, into <code>var/</code>, which is not committed.</li>
        <li>Fund the authority from devnet's faucet, and move a reserve to the faucet key.</li>
        <li>Create the <?= View::e((string) $site['symbol']) ?> mint — <?= View::e((string) $site['decimals']) ?> decimals, mint authority the faucet key, no freeze authority.</li>
        <li>Create the treasury token account, where settled payments land.</li>
        <li>Call <code>initialize_site</code> with the page price, collection threshold and minimum limit.</li>
    </ol>

    <h2>Where it stands</h2>
    <table class="status">
        <tr>
            <th scope="row">authority</th>
            <td><code><?= View::e((string) $status['authority']) ?></code></td>
        </tr>
        <tr>
            <th scope="row">faucet key</th>
            <td><code><?= View::e((string) $status['faucet']) ?></code></td>
        </tr>
        <tr>
            <th scope="row">balance</th>
            <td>
                <?= View::e($sol((int) $status['balance'])) ?> SOL
                of <?= View::e($sol((int) $status['needed'])) ?> needed
            </td>
        </tr>
    </table>

<?php if (isset($status['program_deployed']) && !$status['program_deployed']): ?>
    <section class="gate">
        <h2>The metering program is not deployed</h2>
        <p>
            Nothing at <code><?= View::e((string) $status['program']) ?></code> on this
            cluster is an executable program, so <code>initialize_site</code>
            cannot run. Setup will do everything else and stop there.
        </p>
        <p class="pending">
            SPEC §12.0: nobody but the publisher builds the program — it is
            deployed to devnet once, and one deployment serves many sites
            because the site account is seeded by its authority. If it is
            deployed at another address, set <code>program.id</code> in
            <code>config/site.php</code>.
        </p>
    </section>
<?php endif ?>

<?php if (!$status['funded']): ?>
    <section class="gate">
        <h2>It needs SOL first</h2>
        <p>
            Setup will ask devnet's faucet, and devnet's faucet refuses more
            often than it works — a depleted faucet, a per-address limit and a
            per-IP limit all arrive as the same unhelpful message. If it
            refuses, fund the authority yourself and run setup again. The key is
            kept, so the address will not change.
        </p>
        <p>
            <a href="https://faucet.solana.com">faucet.solana.com</a>, or
            <code>solana airdrop 1 <?= View::e((string) $status['authority']) ?> -u devnet</code>
        </p>
    </section>
<?php endif ?>

    <form method="post" action="/setup">
        <button type="submit">Run setup</button>
    </form>
    <p class="pending">
        It creates accounts on devnet and pays real devnet fees with a key
        worth nothing. Running it twice is safe: every step asks the chain
        whether its work is already done.
    </p>
<?php endif ?>
</article>
