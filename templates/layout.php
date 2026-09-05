<?php
/**
 * @var string $title
 * @var string $content
 * @var array<int, array{heading: string, rows: array<int, array{0: string, 1: string}>}> $inspector
 * @var \Newsprint\Support\View $view
 */
use Newsprint\Support\View;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php /* The home page would otherwise be titled "Newsprint — Newsprint". */ ?>
<title><?= View::e($title) ?><?= $title === 'Newsprint' ? '' : ' — Newsprint' ?></title>
<!--
  SPEC §10.3: this site loads nothing from a domain it does not control. No
  hosted fonts, no analytics, no tag manager, no embedded media. A font pulled
  from a CDN would send every reader's IP address and referring page to that
  CDN on every load, which would make the privacy page's central claim false in
  a way nobody would notice while writing it.
-->
<link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<header class="masthead">
    <a class="wordmark" href="/">Newsprint</a>
    <p class="tagline">A cent an article, and no record of which ones.</p>
</header>

<main>
<?= $content ?>
</main>

<?= $view->render('inspector', ['sections' => $inspector]) ?>

<footer class="footer">
    <p>
        <a href="/privacy">What this site holds about you</a>
    </p>
    <p class="disclaimer">
        A demonstration, not a product. It runs on Solana <strong>devnet</strong>
        with a token it issues itself, worth nothing, that buys nothing. It
        holds its signing key on the web server, which is defensible only
        because that key controls nothing of value.
    </p>
</footer>
</body>
</html>
