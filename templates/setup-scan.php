<?php
/**
 * SPEC §6.3 steps 3 to 5: the link, the code, and *continue*. Shared by every
 * form that starts a scan: setup on the article, and renew and *add to the
 * fund* on `manage_meter`. Hidden until a form has started one; `key.js` fills
 * it from the server's answer.
 *
 * Without JavaScript none of this can start, because the key that the setup
 * names is the page's.
 *
 * @var bool $development the development wallet may answer in place of a phone (§12.6)
 */
?>
<div class="scan" data-setup-scan hidden>
    <div data-setup-offer>
    <p>
        <a data-setup-link href="#">Open this in your wallet</a> on a phone, or
        scan the code with the phone's wallet. It asks for one transaction,
        and shows you what it does before you approve it.
    </p>
    <div class="qr" data-setup-qr></div>
<?php if ($development): ?>
    <?php /* §12.6: labelled as the stand-in it is. */ ?>
    <p class="fine">
        <strong>Development stand-in.</strong> On this machine, the development
        wallet in <code>var/dev-wallet.json</code> can sign in place of a phone.
        <button type="button" class="secondary" data-setup-development>Sign with the development wallet</button>
    </p>
<?php endif ?>
    <p>
        When the wallet says it is done,
        <button type="button" class="wallet" data-setup-continue>Continue</button>
    </p>
    <?php /* Filled by `key.js` on the meter page, for a reader who came there
             from an article: *continue* returns them to it. */ ?>
    <p class="fine" data-back hidden>
        Continue takes you back to <a href="/">the article you came from</a>.
    </p>
    </div>
    <p class="pending" data-setup-status role="status" hidden></p>
</div>
