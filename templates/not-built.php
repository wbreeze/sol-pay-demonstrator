<?php
/** Shown when var/content is missing — a setup problem, so it says how to fix it. */
?>
<article class="piece">
    <h1>Nothing is built yet</h1>
    <p class="lede">
        The content pipeline renders <code>content/*.md</code> ahead of time
        (SPEC §12.7), and it has not run. Its output is deliberately not
        committed.
    </p>
    <pre><code>composer install
bin/build-content
php -S localhost:8000 -t public</code></pre>
</article>
