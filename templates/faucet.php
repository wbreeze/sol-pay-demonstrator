<?php
/**
 * SPEC §4.3: the faucet, which exists for the demo. The reader pastes a wallet
 * address, as they would give one to an exchange, and the site sends to it.
 *
 * **Nothing is behind a click.** Before the form is submitted the screen says
 * what will be sent, that it is once per address, that none of it is worth
 * anything, and that no real site would offer this. One button sends it.
 *
 * A plain form and a plain page in answer: no script, so nothing here depends
 * on one.
 *
 * @var array<string, int|string> $site
 * @var string $demo    the DEMO grant, in whole units
 * @var string $sol     the SOL grant, in whole units
 * @var bool $provisioned
 * @var bool $hosted    whether this is the hosted instance, which may be taken down (SPEC §12.6)
 * @var string $address what was pasted, to show again
 * @var ?array{granted: bool, reason: string, message: string, signature: ?string} $result
 * @var ?string $development the development wallet's address, when it is on (SPEC §12.6)
 */
use Newsprint\Support\View;

$symbol = View::e((string) $site['symbol']);
?>
<article class="piece">
    <h1>The faucet</h1>
    <p class="lede">
        A faucet for this demo. No real site would have one: on mainnet you
        would buy coin somewhere else first, and bring it here in a wallet.
    </p>

<?php if ($result !== null && !$result['granted']): ?>
    <p class="pending" data-faucet-result="refused">
        Nothing was sent: <?= View::e(rtrim($result['message'], '.')) ?>.
    </p>
<?php endif ?>

<?php if (!$provisioned): ?>
    <p class="pending">This copy has not been set up yet. <a href="/setup">First run</a> creates the site on devnet.</p>
<?php else: ?>

<?php if ($result !== null && $result['granted']): ?>
    <section class="gate meter" data-faucet-result="granted">
        <h2>Sent</h2>
        <p>
            <?= View::e($demo) ?> <?= $symbol ?> and <?= View::e($sol) ?> SOL
            are on their way to <code><?= View::e($address) ?></code>.
        </p>
<?php if ($result['signature'] !== null): ?>
        <p class="fine">
            <a href="https://explorer.solana.com/tx/<?= View::e($result['signature']) ?>?cluster=devnet" rel="noreferrer noopener" target="_blank">The transaction on the explorer</a>
        </p>
<?php endif ?>
        <p><a href="/">Go and read something</a>, and set up a meter from any article.</p>
    </section>
<?php else: ?>

    <section class="gate meter">
        <h2>What it sends</h2>
        <ul class="plan">
            <li>
                <strong><?= View::e($demo) ?> <?= $symbol ?></strong>, the
                token this site meters in. It is minted for this demo on
                devnet and is worth nothing.
            </li>
            <li>
                <strong><?= View::e($sol) ?> SOL</strong> on devnet, also
                worth nothing, for the rent and fees your wallet pays when it
                sets up a meter.
            </li>
        </ul>
        <p>
            <strong>Once per address.</strong> The amount is small on purpose:
            it is a little over one minimum limit, so that a fund can run
            short and this site can show what happens then.
        </p>
<?php if ($hosted): ?>
        <?php /* §12.6: said before the reader commits anything, and only where it is true. */ ?>
        <p data-faucet-temporary>
            <strong>This copy of the site is temporary.</strong> It may be
            taken down without notice. A meter left open when that happens
            keeps its rent, about 0.0013 SOL on devnet, and its fund cannot
            be closed until the meter is. Close your meter when you finish
            reading, while the site is still here to do it.
        </p>
<?php endif ?>
        <p class="fine">
            The address you paste is the one thing this site is told about
            your wallet on purpose. It is kept, with the time, so that the
            faucet can refuse a second grant; the transfer itself is public on
            devnet in any case. <a href="/privacy">What this site holds about you</a>.
        </p>

        <form method="post" action="/faucet" class="faucet">
            <label>
                Your wallet's address
                <input type="text" name="address" value="<?= View::e($address) ?>" size="46"
                       autocomplete="off" autocapitalize="off" spellcheck="false" required>
            </label>
            <p><button type="submit" class="wallet">Send <?= View::e($demo) ?> <?= $symbol ?> and <?= View::e($sol) ?> SOL to this address</button></p>
        </form>
<?php if ($development !== null): ?>
        <?php /* §12.6: labelled as the stand-in it is. */ ?>
        <p class="fine">
            <strong>Development stand-in.</strong> The development wallet's
            address is <code data-faucet-development><?= View::e($development) ?></code>.
            Paste it above to fund it as a reader's wallet would be funded.
        </p>
<?php endif ?>
    </section>
<?php endif ?>
<?php endif ?>

    <p class="fine"><a href="/">Back to the paper</a></p>
</article>
