<?php

declare(strict_types=1);

namespace Newsprint\Support;

/**
 * Plain PHP templates, decided at SPEC §12.1: Twig would buy nothing here and
 * §10.3's no-third-party-requests constraint stays trivially satisfied without
 * it. Roughly forty lines, and the escaping is the part that matters.
 */
final class View
{
    private readonly string $publicDir;

    /** @var array<string, string> */
    private array $assets = [];

    public function __construct(private readonly string $templateDir, ?string $publicDir = null)
    {
        $this->publicDir = $publicDir ?? dirname($templateDir).'/public';
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $vars['view'] = $this;
        extract($vars, EXTR_SKIP);

        ob_start();
        require $this->templateDir.'/'.$template.'.php';

        return (string) ob_get_clean();
    }

    /**
     * A file under `public/`, addressed so that a changed file is a new URL:
     * `/assets/key.js` as `/assets/key.js?v=3f9a2c41d0`.
     *
     * A deploy of 2026-10-07 was pulled, built and served, and the author's
     * browser went on running the previous `key.js`. Caddy's file server
     * sends a file with its date and no instruction about caching, so a
     * browser may reuse its copy for a while without asking. A URL that
     * changes with the content needs no cooperation from the host, and works
     * the same under `bin/run-dev`.
     *
     * The version is the start of the file's SHA-256, taken when a page is
     * rendered. So nothing is written into the tree and nothing is built:
     * the templates name the file, and a change to the file changes no
     * template. A missing file keeps its plain path, and the browser reports
     * the 404 that is the real fault.
     */
    public function asset(string $path): string
    {
        return $this->assets[$path] ??= $this->versioned($path);
    }

    private function versioned(string $path): string
    {
        $file = $this->publicDir.$path;
        $hash = is_file($file) ? hash_file('sha256', $file) : false;

        return $hash === false ? $path : $path.'?v='.substr($hash, 0, 10);
    }

    /**
     * Everything from content or from a chain goes through here. The one thing
     * that does not is a built article body, which is rendered HTML by
     * construction — see `bin/build-content`, where the markdown is converted
     * with raw HTML stripped so that a body cannot carry anything §10.3 would
     * disallow.
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The id of a value's row in the inspector's table of short names.
     *
     * Derived from the value rather than from the short name, because the
     * short name is one byte of a hash and two unplaced accounts could in
     * principle draw the same one — a duplicate `id` is a link that goes to
     * the wrong row, which is worse than a duplicate label beside it.
     */
    public static function nameAnchor(string $value): string
    {
        return 'name-'.substr(hash('sha256', $value), 0, 10);
    }

    /**
     * `2026-09-07` as `7 September 2026`.
     *
     * `DateTimeImmutable::format` and not `IntlDateFormatter` or anything else
     * that reads a locale: month names from `format()` are the same on every
     * machine, and this project has been bitten twice by a shell whose locale
     * changed what a number meant. A date the server renders differently from
     * the date in the file is the same fault wearing a nicer hat.
     */
    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new \DateTimeZone('UTC'));

        return $date === false ? '' : $date->format('j F Y');
    }
}
