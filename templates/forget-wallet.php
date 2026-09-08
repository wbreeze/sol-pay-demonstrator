<?php
/**
 * "Forget this wallet" — the sign-out link, relocated and then promoted.
 *
 * It used to sit in the masthead on every page, next to the reader's address,
 * where together they looked like an account with a name and a way out. There
 * is no account: there is a cookie, a row mapping it to one address, and a
 * contract on a public chain that this control does not touch.
 *
 * Which is the whole reason it is safe to offer, and the reason it has to say
 * so. Forgetting the wallet is not closing the contract, and a reader who has
 * just been told that closing "erases what this site holds about you" could
 * reasonably read a nearby button as a quieter version of the same thing. It
 * is not. The delegate stays, the limit stays, the residue stays, and
 * identifying again on any article finds all three — contracts are derived
 * from site and payer, so a fresh session lands on the same account.
 *
 * §10.4 governs erasure and this is not erasure; it is the browser end of the
 * §5 mapping, dropped. The distinction is the text below rather than a
 * footnote, because getting it wrong costs a reader money they think they have
 * revoked.
 *
 * **It renders as a control, not as a footer.** First pass put it under the
 * box with the navigation, where Gato nearly missed it while looking for it —
 * the things below the box are links to elsewhere, and this one *does*
 * something. So it takes the same heading-explanation-button shape as "Raise
 * the limit" and "Close and revoke", and sits between them: least consequential
 * first is the wrong order for a menu of exits, but middle is where a reader
 * scanning for an action actually looks, and closing keeps the last word
 * because its four disclosures have to run uninterrupted into its button.
 *
 * @var bool $has_contract
 * @var array<string, string>|null $contract
 */
use Newsprint\Support\View;
?>
            <h2>Forget this wallet</h2>
<?php if ($has_contract): ?>
            <p>
                Forgetting drops the cookie and the row that tie this browser to
                your address. That is the whole of it: the browser end of the
                arrangement, and nothing on chain.
            </p>
            <p>
                <strong>Your contract stays open and this site stays a delegate
                on your token account.</strong> The limit, the
                <?= View::e((string) $contract['unpaid']) ?> you are carrying and
                your live grants are all untouched. Connect the same wallet again
                and the meter picks up exactly where it is — a contract's address
                is derived from this site and your wallet, so nothing has to be
                remembered in order to find it.
            </p>
            <p class="fine">
                To end the authorization rather than the session, that is
                <strong>Close and revoke</strong>, below.
            </p>
<?php else: ?>
            <p>
                Forgetting drops the cookie and the row that tie this browser to
                your address, which is all there is to drop. You have no
                contract, so there is nothing on chain to change and nothing to
                close.
            </p>
<?php endif ?>
            <form class="forget" method="post" action="/signout">
                <button type="submit">Forget this wallet</button>
            </form>
