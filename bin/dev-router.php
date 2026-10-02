<?php

declare(strict_types=1);

/**
 * Router for `php -S`, used only by `bin/run-dev`. Never loaded by a real SAPI.
 *
 * It exists for one reason, which cost a testing round before it did.
 *
 * **Static files are served with `Cache-Control: no-store`.** The built-in
 * server sends no cache headers at all, so a browser applies its own heuristic
 * and may keep `assets/key.js` across a restart. The symptom is the worst
 * kind: the page runs code that is not the code on disk, and the person
 * testing has no way to tell. Returning `false` from a router lets the server
 * serve the file, but headers set here are dropped when it does — so this
 * serves them itself.
 */

$root = realpath(__DIR__.'/../public');
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// Resolve, then check the resolved path is still inside the document root:
// a request for /../config/site.php must not read outside it.
$candidate = realpath($root.$path);

if ($path !== '/' && $candidate !== false && is_file($candidate) && str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)) {
    $types = [
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
        'map' => 'application/json',
        'txt' => 'text/plain; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
    ];
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));

    header('Content-Type: '.($types[$extension] ?? 'application/octet-stream'));
    header('Content-Length: '.filesize($candidate));
    // Development only. What is being bought is the certainty that the browser
    // is running the file on disk.
    header('Cache-Control: no-store, no-cache, must-revalidate');

    readfile($candidate);

    return true;
}

require $root.'/index.php';
