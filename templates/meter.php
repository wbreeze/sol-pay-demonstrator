<?php
/**
 * The meter, on the article, where the decision to spend is actually made.
 *
 * This is `set_meter` from sol-pay's state diagram for a browser with no
 * meter, and `manage_meter`'s short form for one whose meter cannot take the
 * next charge. There is no sign-in screen (SPEC §5.6): identifying happens
 * here, beside the money.
 *
 * **Setting a meter is being rebuilt for the fund design.** The browser key
 * and its proof (SPEC §5) and the setup scan (SPEC §6.3) replace the wallet
 * in the page, and until they land this panel says so rather than offering a
 * control that would not work. A browser that already holds a session still
 * meters, blocks and fails here as it will afterwards.
 *
 * @var array<string, mixed> $meter
 * @var array<string, int|string> $site
 */
use Newsprint\Support\Causes;
use Newsprint\Support\View;
use SolPay\Core\Units;

$symbol = View::e((string) $meter['symbol']);
$onChain = $meter['meter'];
?>
<section class="gate meter" data-meter
         data-stage="<?= View::e((string) $meter['stage']) ?>">

<?php if (!$meter['provisioned']): ?>
    <h2>The rest is metered</h2>
    <p class="pending">This copy has not been set up yet. <a href="/setup">First run</a> creates the site on devnet.</p>

<?php elseif ($meter['stage'] === 'unreadable'): ?>
    <h2>The rest is metered</h2>
    <p class="pending">
        Currently unable to consult the meter.
    </p>

<?php elseif ($meter['stage'] === 'anonymous'): ?>
    <h2>The rest is metered</h2>
    <p>
        Reading on costs <?= View::e((string) $site['page_price_demo']) ?> <?= $symbol ?>
        an article, drawn from a fund you control, under a limit and an expiry
        you set yourself.
    </p>
<?php if ($meter['ended']): ?>
    <p class="pending">
        The meter this browser held has been closed, or renewed from another
        device, which then holds it. This browser's session with it has ended.
    </p>
<?php endif ?>
    <p class="pending">
        The meter is being rebuilt. Setting one up from this page will return
        shortly; until then the ledes are free to read.
    </p>

<?php elseif ($meter['stage'] === 'failed'): ?>
    <?php /* §8.2. Under the fund design SPL's `InsufficientFunds` means one
             thing: the fund holds less than the unpaid total. So the screen
             can say how much, and that the answer is money. */ ?>
    <h2>The charge did not go through</h2>
<?php $shortfall = $meter['result']?->shortfall; ?>
<?php if ($shortfall !== null && $shortfall > 0): ?>
    <p>
        Your fund is short by
        <?= View::e(Units::fromBaseUnits($shortfall, (int) $meter['decimals'])) ?>
        <?= $symbol ?> of what this settle would move. The fund holds
        <?= View::e((string) $meter['balance']) ?> <?= $symbol ?>.
    </p>
<?php endif ?>
<?php $said = Causes::describe($meter['result']?->cause); ?>
<?php if ($said !== null): ?>
    <p class="fine">The chain said: <code><?= View::e($said) ?></code></p>
<?php else: ?>
    <p class="fine"><?= View::e((string) ($meter['result']?->detail ?? '')) ?></p>
<?php endif ?>
    <p class="fine">
        Nothing was charged for this page. Transaction logs are not copied into
        this site's own logs (§8.1) — if you want to read them, they are yours,
        on the explorer.
    </p>

<?php elseif ($meter['stage'] === 'expired'): ?>
    <h2>Your meter has expired</h2>
    <p>
        It expired at <?= View::e((string) $onChain['expiry']) ?>, having used
        <?= View::e((string) $onChain['used']) ?> of
        <?= View::e((string) $onChain['limit']) ?> <?= $symbol ?>.
        Nothing can be metered on it until it is renewed.
    </p>
    <p><a href="/meter">The meter</a></p>

<?php elseif ($meter['stage'] === 'limit'): ?>
    <h2>You have reached your limit</h2>
    <p>
        Used <?= View::e((string) $onChain['used']) ?> of
        <?= View::e((string) $onChain['limit']) ?> <?= $symbol ?>,
        of which <?= View::e((string) $onChain['paid']) ?> has settled and
        <?= View::e((string) $onChain['unpaid']) ?> has not.
    </p>
    <p><a href="/meter">The meter</a></p>

<?php else: ?>
    <h2>The meter is running</h2>
    <p>
        Limit <?= View::e((string) $onChain['limit']) ?> <?= $symbol ?>
        · used <?= View::e((string) $onChain['used']) ?>
        · settled <?= View::e((string) $onChain['paid']) ?>
        · <?= View::e((string) $meter['items_remaining']) ?> views left.
    </p>
    <?php /* This branch is the panel's fallback and nothing reaches it through
             the article (2026-09-11). `meter.php` renders only where the body
             is withheld, and a reader whose meter is not blocked gets the
             body — so `metered` here needs a null `MeterResult`, which the
             POST always sets and the GET only reaches with a grant. The
             branch stays so that no stage can render blank, which
             `TemplateRenderTest` checks. */ ?>
<?php endif ?>

<?php /* §6 asks manage_meter to carry a permanent link from the meter, not
         only at the limit. Here it renders on every stage that holds a
         session, including `unreadable`, where the chain cannot be read. */ ?>
<?php if ($onChain !== null || $meter['stage'] === 'unreadable'): ?>
    <p class="fine">
        <a href="/meter">The meter</a> — what you have spent, and where it stands.
    </p>
<?php endif ?>
</section>
