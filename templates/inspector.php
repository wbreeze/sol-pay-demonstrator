<?php
/**
 * SPEC §9. Present on every screen, collapsed by default, one click from any
 * page. Claim 7 in §2 is its job: every number on the screen came from an
 * account, not from the server's memory.
 *
 * A row is a label and a value, and optionally a third cell — §9 asks for one
 * beside every preflight answer naming the on-chain check it mirrors, and a
 * number with no claim next to it cannot be wrong about anything.
 *
 * **An address row fills that same cell from the address itself**, with the
 * derivation that produced it or, where nothing derived it, where it came from
 * instead. The cell is one thing in both cases — a claim about the value
 * beside it — so it keeps one anatomy and one column, and a row that has no
 * claim to make spans the two.
 *
 * `$sections` is null when this request read nothing from the chain, and the
 * panel is therefore deferred to first open. See the branch below.
 *
 * @var array<int, array{heading: string, rows: array<int, array{0: string, 1: string|array{address: string, alias: ?string, explorer: bool, derivation: ?string}, 2?: string}>}>|null $sections
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Support\View;
?>
<details class="inspector">
    <summary>Inspector</summary>
    <div class="inspector-body">
<?php if ($sections === null): ?>
<?php
    /* Deferred (2026-09-09). Nothing else in this request needed the chain, and
       §9 says this panel is collapsed by default — so reading an account here
       spends a `getMultipleAccounts` on every page view to fill a panel most
       readers never open. Measured: that one call was the whole of a 0.9 s
       `/privacy`, a page that displays no chain data at all.

       This is the argument §9 already made one step further in, for the
       transaction event, settled the same way: the read happens when a reader
       asks for it.

       **The link is not decoration.** Without JavaScript it *is* the panel — it
       goes to a page that renders these same sections server-side, so nothing
       here depends on a script to stay reachable. With JavaScript,
       `assets/inspector.js` intercepts the first open, asks the same URL for a
       fragment, and puts it where this paragraph is. */
?>
        <p class="inspector-preamble" data-panel-src="/inspector/panel">
            This page needed nothing from the chain, so it read nothing. The
            inspector reads the accounts when you open it —
            <a href="/inspector/panel">read them now</a>.
        </p>
<?php else: ?>
<?php
    /* One line, and a link (2026-09-14).
       -----------------------------------
       Two preamble paragraphs stood here, and a `note` stood under every
       section below: what a short name is, the three kinds of provenance, why
       the endpoint is called by the server, what the delegate line means, why
       "last" means this request's. All of it true, all of it in the way of the
       numbers a reader opened the panel to read.

       It is now one article, written for the purpose, and every sentence that
       was here is in it at more length than a panel could carry. A panel that
       explains itself twelve times over is a panel nobody scrolls to the end
       of.

       **The invention clause stays, and stays here.** §9 requires this panel
       to say once that the short names are this site's own and mean nothing to
       a wallet or an explorer — and that obligation cannot be discharged by a
       page the reader has not opened. Everything else can.

       **It lives in `inspector-sections.php`, not here.** That partial exists
       so two callers render the same panel — this template, and the fragment
       `GET /inspector/panel` returns when a reader opens a deferred one. The
       preamble was here, so a deferred page never showed it at all: on
       `/privacy`, on the article shell, on this piece's own page, a reader who
       opened the panel got the sections and no line. That was survivable while
       the panel explained itself six more times below. It is not survivable
       now, and §9's obligation is not discharged by a sentence most readers
       never reach. */
?>
<?= $view->render('inspector-sections', ['sections' => $sections]) ?>
<?php endif ?>
    </div>
    <?php /* Two jobs: the copy buttons on every address row, and — on the
             pages that have one — the deferred read of the transaction's
             event. It used to load only for the second, which stopped being
             true when addresses gained controls. Local file, per §10.3. */ ?>
    <script type="module" src="/assets/inspector.js"></script>
</details>
