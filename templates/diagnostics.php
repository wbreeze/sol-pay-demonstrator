<?php
/**
 * Not a screen in SPEC §6. A workbench.
 *
 * It exists because the sign-in screen refused Phantom in two browsers with a
 * message computed from a feature list nobody had read, and the difference
 * between "the wallet does not offer it", "it offers it under another name"
 * and "the check is wrong" cannot be settled from a specification.
 *
 * It filters nothing and it calls what it is told to call.
 *
 * @var \Newsprint\Support\View $view
 */
?>
<article class="piece">
    <h1>Wallet diagnostics</h1>
    <p class="lede">
        Every wallet that announces itself to this page, with every feature key
        it advertises, unfiltered. The buttons call the wallet whether or not
        the feature is advertised, because a call that works anyway is a
        finding.
    </p>

    <p class="fine">
        Writes to <code>var/wallet-reports/</code>, which is not committed.
        Nothing here touches the chain or the store.
    </p>

    <div data-wallet-list></div>

    <p>
        <button type="button" class="wallet" data-save>Write the report to var/</button>
        <button type="button" class="secondary" data-copy>Copy it</button>
    </p>
    <p class="pending" data-diag-status hidden></p>

    <h2>The report</h2>
    <pre class="report" data-report>collecting…</pre>

    <script type="module" src="/assets/diagnose.js"></script>
</article>
