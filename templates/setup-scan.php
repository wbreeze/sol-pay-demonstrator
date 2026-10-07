<?php
/**
 * SPEC §6.3 steps 3 to 5: what the wallet will ask, the link, the code, and
 * *continue*. Shared by every form that starts a scan: setup on the article,
 * and renew and *add to the fund* on `manage_meter`. Hidden until a form has
 * started one; `key.js` fills it from the form's answers and the server's.
 *
 * **The scan takes the place of the form that started it.** Shown under the
 * form, the scan was below the fold on a phone, and pressing the button
 * looked like nothing. So `key.js` hides the form and puts this where the
 * form was, and *change the answers* brings the form back as the reader left
 * it.
 *
 * **This is where the reader is told what they are approving**, in full. The
 * wallet shows about thirty characters of the transaction's message
 * (`Composition`), so the complete account has to be on this side of the
 * gesture. One table serves the three kinds: each row names the kinds that
 * show it, and `key.js` writes the answers into the `data-answer` slots.
 *
 * Without JavaScript none of this can start, because the key that the setup
 * names is the page's.
 *
 * @var string $symbol      the token's symbol, already escaped by the caller
 * @var bool   $development the development wallet may answer in place of a phone (§12.6)
 */
?>
<div class="scan" data-setup-scan tabindex="-1" hidden>
    <div data-setup-offer>
    <?php /* Short enough that the code below is on a phone's first screen:
             one line a row, at the fine size. The forms carry the longer
             explanations, and the reader has just read them. */ ?>
    <p>Your wallet will ask you to approve one transaction:</p>
    <table class="status">
        <tr data-kinds="setup">
            <th scope="row">fund</th>
            <td><span data-answer="index"></span>, opened if it is new</td>
        </tr>
        <tr data-kinds="setup renew deposit" data-deposit>
            <th scope="row">deposit</th>
            <td><span data-answer="deposit"></span> <?= $symbol ?> into the fund</td>
        </tr>
        <tr data-kinds="setup">
            <th scope="row">meter</th>
            <td>opened, or renewed, for this browser</td>
        </tr>
        <tr data-kinds="renew">
            <th scope="row">meter</th>
            <td>renewed, carrying what is unpaid</td>
        </tr>
        <tr data-kinds="deposit">
            <th scope="row">meter</th>
            <td>untouched</td>
        </tr>
        <tr data-kinds="setup renew">
            <th scope="row">limit</th>
            <td><span data-answer="limit"></span> <?= $symbol ?></td>
        </tr>
        <tr data-kinds="setup renew">
            <th scope="row">expires</th>
            <td>after <span data-answer="expiry"></span></td>
        </tr>
    </table>
    <p class="fine">
        The button opens a wallet on this device. From a desk, scan the code
        with the phone's wallet.
    </p>
    <?php /* The button and the code are the two ways into the wallet, so they
             are drawn as a pair: one width, one left edge. */ ?>
    <a class="wallet" data-setup-link href="#">Open this in your wallet</a>
    <div class="qr" data-setup-qr></div>
<?php if ($development): ?>
    <?php /* §12.6: labelled as the stand-in it is. */ ?>
    <p class="fine">
        <strong>Development stand-in.</strong> On this machine, the development
        wallet in <code>var/dev-wallet.json</code> can sign in place of a phone.
        <button type="button" class="secondary" data-setup-development>Sign with the development wallet</button>
    </p>
<?php endif ?>
    <?php /* Why *continue* did not go through, filled by `key.js`: most often the
             wallet's transaction has not landed yet. Above the button, like
             a form's refusal, and for the same reason. */ ?>
    <p class="refusal" data-setup-refusal role="alert" hidden>
        <strong>Continue did not go through:</strong>
        <span data-setup-reason></span>.
    </p>
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
    <?php /* The way back to the form. Nothing has been signed, so there is
             nothing to undo: the link above stops answering within ten
             minutes (§6.3 step 2). */ ?>
    <p data-setup-offer>
        <button type="button" class="secondary" data-setup-cancel>Change the answers</button>
    </p>
</div>
