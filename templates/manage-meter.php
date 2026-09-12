<?php
/**
 * `manage_meter` (§6): what you have spent, and the way out.
 *
 * Reachable at any time. The close section carries §10.4's four disclosures
 * **before** the button, not after it, because each of them could change the
 * decision:
 *
 * 1. what is deleted, and that the paying wallet is forgotten with it;
 * 2. that it costs you the articles you have already paid for, and how many;
 * 3. that the faucet ledger survives, and why that exception is allowed;
 * 4. that closing is itself written to a public ledger, permanently — the
 *    instruction to be forgotten is recorded by the act of giving it.
 *
 * The fourth is the sharpest illustration of §10.2's caveat this site has, and
 * §10.4 says outright that it belongs here rather than in a footnote.
 *
 * @var string $stage
 * @var array<string, int|string> $site
 * @var string|null $wallet
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Support\View;

$symbol = View::e((string) ($symbol ?? $site['symbol']));
?>
<article class="piece">
    <h1>The meter</h1>

<?php if ($stage === 'anonymous'): ?>
    <p class="lede">
        Nothing here is yours yet. The meter appears on any article, and it is
        where you connect a wallet and set a limit.
    </p>
    <p><a href="/">Go and read something</a>.</p>

<?php elseif ($stage === 'unreadable'): ?>
    <p class="lede">The chain could not be read just now, so this page cannot say where you stand.</p>
    <p class="pending">Nothing was charged and nothing was changed. Try again in a moment.</p>

    <?php /* And the way out is still here (2026-09-11). This page used to end
             at the line above, which withdrew §10.4's one unconditional
             promise on exactly the day it mattered: the endpoint is slow, the
             article's panel cannot say where the reader stands either, and the
             site is still holding their address. `POST /signout` never touches
             the chain, so there is nothing about a bad endpoint that makes it
             unavailable — only this template, which did not render it.

             `has_contract` is null rather than false: what the chain would
             have said is exactly what is unknown here. */ ?>
    <section class="gate meter">
<?= $view->render('forget-wallet', ['has_contract' => null, 'contract' => null]) ?>
    </section>

<?php elseif ($stage === 'no-contract'): ?>
    <p class="lede">
        This wallet has no contract with this site, so there is nothing to
        manage and nothing to close.
    </p>
    <p class="fine">
        Paying wallet <code><?= View::e(substr((string) $wallet, 0, 4).'…'.substr((string) $wallet, -4)) ?></code>
    </p>
    <p><a href="/">Open an article</a> and the meter will offer you a limit.</p>

    <section class="gate meter">
<?= $view->render('forget-wallet', ['has_contract' => false, 'contract' => null]) ?>
    </section>

<?php else: ?>
    <section class="gate meter" data-manage
             data-chain="<?= View::e((string) $chain) ?>"
             data-payer="<?= View::e((string) $wallet) ?>"
             data-program="<?= View::e((string) $program) ?>"
             data-token-program="<?= View::e((string) $token_program) ?>">

        <p class="fine">
            Paying wallet <code><?= View::e(substr((string) $wallet, 0, 4).'…'.substr((string) $wallet, -4)) ?></code>
            · contract <code><?= View::e(substr((string) $contract['address'], 0, 4).'…'.substr((string) $contract['address'], -4)) ?></code>
        </p>

        <table class="status">
            <tr><th scope="row">limit</th><td><?= View::e((string) $contract['limit']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">used</th><td><?= View::e((string) $contract['used']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">settled</th><td><?= View::e((string) $contract['paid']) ?> <?= $symbol ?></td></tr>
            <tr><th scope="row">unpaid</th><td><?= View::e((string) $contract['unpaid']) ?> <?= $symbol ?> — carried, not owed until it settles</td></tr>
            <tr><th scope="row">balance</th><td><?= View::e((string) $balance) ?> <?= $symbol ?></td></tr>
            <tr>
                <th scope="row">delegate</th>
                <td>
<?php if ($delegate === null): ?>
                    none — nothing may draw from your token account
<?php else: ?>
                    <code><?= View::e(substr((string) $delegate, 0, 4).'…'.substr((string) $delegate, -4)) ?></code>
                    may draw up to <?= View::e((string) $approved) ?> <?= $symbol ?>
<?php endif ?>
                </td>
            </tr>
<?php if ($views_remaining !== null): ?>
            <tr><th scope="row">views left</th><td><?= View::e((string) $views_remaining) ?></td></tr>
<?php endif ?>
        </table>

<?php if ($blocked !== null): ?>
        <p class="pending">
            The meter is blocked: <code><?= View::e((string) $blocked) ?></code>.
            Renewing raises the limit; closing ends it.
        </p>
<?php endif ?>

        <?php /* The delegate is the whole of what authorizing gave away, and
                 §2's claim 6 is about it — but it lives on the SPL token
                 account rather than in the contract, and a wallet will show a
                 balance without ever mentioning it. So the site shows it, and
                 then points somewhere the site does not control.

                 The explorer link is a link, not a resource this page loads:
                 §10.3 forbids the second and says nothing about the first. */ ?>
        <details class="fine">
            <summary>What "delegate" means, and how to check it yourself</summary>
            <p>
                Authorizing did two things. It created a contract account, and
                it named that contract as a <strong>delegate</strong> on your
                token account, allowed to draw up to the limit you chose.
                Closing removes both — that is what <code>revoke</code> is for.
            </p>
            <p>
                The delegate is a field on the token account, not on the
                contract, and most wallets never display it. Rather than ask
                you to take this page's word for it, here is the account:
            </p>
            <p>
                <code><?= View::e((string) $token_account) ?></code><br>
                <a href="https://explorer.solana.com/address/<?= View::e((string) $token_account) ?>?cluster=devnet"
                   rel="noreferrer noopener" target="_blank">Open it on the Solana explorer</a>
                and look for the delegate. Before you close it names this site's
                contract; afterwards there should be none.
            </p>
        </details>

        <div data-controls>
            <h2>Raise the limit</h2>
            <p>
                Renewing sets a new ceiling, reduces <code>used</code> by what
                has settled, and zeroes <code>paid</code>. The smallest limit
                you can set now is <?= View::e((string) $limit_floor) ?>
                <?= $symbol ?>, which folds in the
                <?= View::e((string) $contract['unpaid']) ?> you are carrying.
            </p>
            <label class="limit">
                Limit
                <input type="text" inputmode="decimal" data-limit
                       value="<?= View::e((string) $limit_floor) ?>" size="8">
                <?= $symbol ?>
            </label>
            <p><button type="button" class="wallet" data-renew>Renew</button></p>

<?= $view->render('forget-wallet', ['has_contract' => true, 'contract' => $contract]) ?>

            <h2>Close and revoke</h2>
            <p>
                Closing ends the contract and withdraws this site's authority
                over your token account. Your wallet will show no delegate
                afterwards — that is the check worth doing.
            </p>
            <p>
                The <?= View::e((string) $contract['unpaid']) ?> <?= $symbol ?>
                you are carrying is <strong>forgiven</strong>, not collected.
                The site takes that loss on purpose.
            </p>

            <h3>What closing deletes</h3>
            <ul class="plan">
                <li>Your <strong>session</strong> — the row tying this browser to your wallet address. The paying wallet is forgotten.</li>
                <li>
                    Your <strong>view grants</strong> —
<?php if ($live_grants > 0): ?>
                    <?= View::e((string) $live_grants) ?> live right now.
                    <strong>Those articles stop being served</strong>, even
                    though you have paid for them. That is the cost, it is
                    bounded at <?= View::e((string) $site['page_price_demo']) ?>
                    <?= $symbol ?> each, and you are being told before you
                    click rather than after.
<?php else: ?>
                    none are live right now, so this costs you nothing today.
<?php endif ?>
                </li>
                <li>
                    The <strong>faucet ledger row survives</strong>, and the
                    reason is published rather than assumed: the faucet's mint
                    is an on-chain transaction naming your token account
                    forever, so the row duplicates a public fact. Without it,
                    close-and-refaucet would be a loop.
                </li>
            </ul>

            <h3>What closing writes</h3>
            <p>
                <code>close_contract</code> emits
                <code>Closed { contract, forgiven }</code> to the chain. Your
                instruction to be forgotten is itself recorded, publicly and
                permanently, by the act of giving it. That is not an argument
                against closing — it is the clearest thing this site can show
                you about what a public ledger is.
            </p>

            <p><button type="button" class="wallet" data-close>Close and revoke</button></p>
        </div>

        <div data-receipt hidden></div>
        <p class="pending" data-status hidden></p>
    </section>

    <script type="module" src="/assets/manage.js"></script>
<?php endif ?>

    <p class="fine"><a href="/">Back to the paper</a> · <a href="/privacy">What this site holds about you</a></p>
</article>
