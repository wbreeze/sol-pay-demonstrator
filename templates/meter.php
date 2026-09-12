<?php
/**
 * The meter, on the article, where the decision to spend is actually made.
 *
 * This is `set_meter` from sol-pay's state diagram — "inform cost per fetch,
 * prompt limit amount, enforce minimum on limit amount" — and `authorize`
 * behind it. There is no sign-in screen: the diagram has no such node, and a
 * page whose only purpose is to collect an identity reads as identification
 * for tracking. Identifying happens here, one click before the money, where a
 * reader can see exactly what it is for.
 *
 * Two clicks and two wallet dialogs the first time, and the split is not
 * cosmetic. Each wallet call has to originate from a real user gesture, and
 * after the first `await` the second call is no longer inside one — Android
 * Chrome blocks the intent navigation on that basis (§6.3). One dialog per
 * click is also the honest description of what is happening: one proves who
 * you are, one authorizes a spending limit.
 *
 * **The words are not in here.** Lines are in `config/strings.php`, paragraphs
 * in `content/ui/meter.md`; this file decides which of them a stage shows and
 * what values they are filled with. What it loses is the sentence sitting
 * beside the comment explaining it — the comments stay, and now name the key
 * they are about.
 *
 * @var array<string, mixed> $meter
 * @var array<string, int|string> $site
 * @var \Newsprint\Support\Copy $copy
 */
use Newsprint\Support\Causes;
use Newsprint\Support\View;
use SolPay\Core\Units;

$symbol = View::e((string) $meter['symbol']);
$contract = $meter['contract'];
?>
<section class="gate meter" data-meter
         data-stage="<?= View::e((string) $meter['stage']) ?>"
         data-chain="<?= View::e((string) $meter['chain']) ?>"
         data-payer="<?= View::e((string) ($meter['wallet'] ?? '')) ?>"
         data-program="<?= View::e((string) $meter['program']) ?>"
         data-token-program="<?= View::e((string) $meter['token_program']) ?>">

<?php if (!$meter['provisioned']): ?>
    <h2><?= $copy->line('meter.gate.heading') ?></h2>
    <p class="pending"><?= $copy->line('meter.unprovisioned.line') ?></p>

<?php elseif ($meter['stage'] === 'unreadable'): ?>
    <h2><?= $copy->line('meter.gate.heading') ?></h2>
    <p class="pending"><?= $copy->line('meter.unreadable.line') ?></p>

<?php elseif ($meter['stage'] === 'anonymous'): ?>
    <?php /* The diagram's `identified` choice, and its "viewer not identified"
             branch. One click, one wallet dialog, and no page. */ ?>
    <h2><?= $copy->line('meter.gate.heading') ?></h2>
<?= $copy->block('meter.anonymous.cost', ['page_price' => (string) $site['page_price_demo'], 'symbol' => (string) $meter['symbol']]) ?>
<?= $copy->block('meter.anonymous.wallet') ?>
    <p>
        <button type="button" class="wallet" data-identify><?= $copy->line('meter.anonymous.button') ?></button>
    </p>
    <div data-wallet-choices hidden></div>

<?php elseif ($meter['stage'] === 'unfunded'): ?>
    <?php /* §4.3. `approve_checked` against a token account that does not
             exist fails at the runtime, and the reader would never learn why,
             so the faucet is the branch before `set_meter` and not a screen
             they have to go and find. */ ?>
    <h2><?= $copy->line('meter.unfunded.heading', ['symbol' => (string) $meter['symbol']]) ?></h2>
<?= $copy->block('meter.unfunded.what', ['symbol' => (string) $meter['symbol']]) ?>
<?= $copy->block('meter.unfunded.faucet', ['demo' => (string) $meter['faucet']['demo'], 'sol' => (string) $meter['faucet']['sol'], 'symbol' => (string) $meter['symbol']]) ?>
<?php if ($meter['faucet']['available']): ?>
    <p><button type="button" class="wallet" data-faucet><?= $copy->line('meter.unfunded.button', ['amount' => (string) $meter['faucet']['demo'], 'symbol' => (string) $meter['symbol']]) ?></button></p>
<?php else: ?>
<?= $copy->block('meter.unfunded.spent', ['balance' => (string) $meter['balance'], 'symbol' => (string) $meter['symbol']], 'pending') ?>
<?php endif ?>

<?php elseif ($meter['stage'] === 'set-meter'): ?>
    <?php /* `set_meter`: inform cost per fetch, prompt limit, enforce the
             minimum — all three before any wallet dialog opens. */ ?>
    <h2><?= $copy->line('meter.set_meter.heading') ?></h2>
<?= $copy->block('meter.set_meter.cost', ['page_price' => (string) $site['page_price_demo'], 'symbol' => (string) $meter['symbol']]) ?>
<?= $copy->block('meter.set_meter.trust', [], 'fine') ?>

    <p class="fine">
        <?= $copy->line('meter.set_meter.wallet', [
            'wallet' => substr((string) $meter['wallet'], 0, 4).'…'.substr((string) $meter['wallet'], -4),
            'balance' => (string) $meter['balance'],
            'symbol' => (string) $meter['symbol'],
        ]) ?>
    </p>

    <label class="limit">
        <?= $copy->line('meter.set_meter.label') ?>
        <input type="text" inputmode="decimal" data-limit
               value="<?= View::e((string) $meter['limit_floor']) ?>"
               size="8">
        <?= $symbol ?>
    </label>
    <p class="fine" data-floor>
        <?= $copy->line('meter.set_meter.floor', ['floor' => (string) $meter['limit_floor'], 'symbol' => (string) $meter['symbol']]) ?>
    </p>

    <p>
        <button type="button" class="wallet" data-authorize><?= $copy->line('meter.set_meter.button') ?></button>
    </p>

<?php elseif ($meter['stage'] === 'failed'): ?>
    <?php /* §8.2. `InsufficientFunds` is ambiguous by construction: SPL
             reports a short balance and a short allowance identically and the
             two need opposite responses, so the site reads the account rather
             than guessing from the code. Both can be short at once, and then
             both are shown in this order — a re-approval the balance cannot
             cover fixes nothing. */ ?>
    <h2><?= $copy->line('meter.failed.heading') ?></h2>
<?php $shortfall = $meter['result']?->shortfall; ?>
<?php if ($shortfall !== null && $shortfall->balanceShort > 0): ?>
<?= $copy->block('meter.failed.balance', ['short' => Units::fromBaseUnits($shortfall->balanceShort, (int) $meter['decimals']), 'symbol' => (string) $meter['symbol']]) ?>
<?php if ($meter['faucet']['available']): ?>
    <p><button type="button" class="wallet" data-faucet><?= $copy->line('meter.unfunded.button', ['amount' => (string) $meter['faucet']['demo'], 'symbol' => (string) $meter['symbol']]) ?></button></p>
<?php else: ?>
    <?php /* **The one branch that could dead-end** — `meter.failed.dead_end`.
             The faucet is spent, the balance cannot cover the charge, and
             renewing raises a ceiling that was never the problem, so without
             this there is nothing on the screen to do next: half of §8's rule
             rather than all of it.

             A link and not a button, deliberately. Closing is a wallet
             transaction that forgives a residue, purges live grants and signs
             the reader out, and §10.4 qualification 2 says that cost is
             "stated on the close confirmation rather than discovered". */ ?>
<?= $copy->block('meter.failed.dead_end') ?>
<?php endif ?>
<?php endif ?>
<?php if ($shortfall !== null && $shortfall->allowanceShort > 0): ?>
<?= $copy->block('meter.failed.allowance', ['short' => Units::fromBaseUnits($shortfall->allowanceShort, (int) $meter['decimals']), 'symbol' => (string) $meter['symbol']]) ?>
    <p><?= $copy->line('meter.failed.renew') ?></p>
<?php endif ?>
<?php if ($shortfall !== null && !$shortfall->delegatePresent): ?>
<?= $copy->block('meter.failed.delegate') ?>
    <p><?= $copy->line('meter.failed.renew') ?></p>
<?php endif ?>
<?php $said = Causes::describe($meter['result']?->cause); ?>
<?php if ($said !== null): ?>
    <p class="fine"><?= $copy->line('meter.failed.said', ['cause' => $said]) ?></p>
<?php else: ?>
    <p class="fine"><?= View::e((string) ($meter['result']?->detail ?? '')) ?></p>
<?php endif ?>
<?= $copy->block('meter.failed.nothing_charged', [], 'fine') ?>

<?php elseif ($meter['stage'] === 'limit'): ?>
    <h2><?= $copy->line('meter.limit.heading') ?></h2>
<?= $copy->block('meter.limit.used', [
    'used' => (string) $contract['used'],
    'limit' => (string) $contract['limit'],
    'paid' => (string) $contract['paid'],
    'unpaid' => (string) $contract['unpaid'],
    'symbol' => (string) $meter['symbol'],
]) ?>
<?= $copy->block('meter.limit.exit') ?>

<?php else: ?>
    <h2><?= $copy->line('meter.running.heading') ?></h2>
    <p>
        <?= $copy->line('meter.running.line', [
            'limit' => (string) $contract['limit'],
            'symbol' => (string) $meter['symbol'],
            'used' => (string) $contract['used'],
            'paid' => (string) $contract['paid'],
            'views' => (string) $meter['views_remaining'],
        ]) ?>
    </p>
    <?php /* This branch is the panel's fallback and nothing reaches it through
             the article (2026-09-11). The branch stays so that no stage can
             render blank, which `TemplateRenderTest` checks. */ ?>
<?php endif ?>

<?php /* §6 asks manage_meter to carry a permanent link from the meter widget,
         not only at the limit — a reader who has authorized a site to draw
         from their wallet should not have to exhaust something to find the
         exit. It was written into the branch above, which nobody reaches, so
         for months it was permanent in the way a locked fire door is.

         Here it renders on **every stage that stores a wallet**, including the
         three a stuck reader is actually in — `unfunded`, `set-meter`, and
         `unreadable`, where the chain cannot be read and §10.4's promise is
         the one thing that still works, because `POST /signout` never asks the
         chain anything.

         Two wordings, because one would be false somewhere: §6's case is about
         a reader who *authorized*, and a reader without a contract has not.
         Their claim is §10.4's instead — the site is holding an address — so
         that is what the line says to them. Stages that offer their own
         `/meter` link in context keep it; this is the one that is always in
         the same place. */ ?>
<?php if ($meter['wallet'] !== null): ?>
    <p class="fine">
<?php /* Branching outside the call, not inside it: a key composed at the call
         site is a key `CopyTest` cannot find, and one nobody can grep. */ ?>
<?php if ($contract !== null): ?>
        <?= $copy->line('meter.exit.spent') ?>
<?php else: ?>
        <?= $copy->line('meter.exit.held') ?>
<?php endif ?>
    </p>
<?php endif ?>

    <p class="pending" data-meter-status hidden></p>
</section>

<?php if ($meter['provisioned'] && in_array($meter['stage'], ['anonymous', 'unfunded', 'set-meter'], true)): ?>
<?php /* §12.2: JavaScript where the wallet is, and nowhere else. Not loaded on
         a page with nothing for it to do. */ ?>
<script type="module" src="/assets/meter.js"></script>
<?php endif ?>
