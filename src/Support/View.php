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
    /**
     * The copy is handed to every template the way `$view` is, because a
     * template that has to be *given* its words by every caller is a template
     * whose callers all have to remember to.
     */
    public function __construct(private readonly string $templateDir, private readonly ?Copy $copy = null)
    {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $vars['view'] = $this;
        $vars['copy'] ??= $this->copy;
        extract($vars, EXTR_SKIP);

        ob_start();
        require $this->templateDir.'/'.$template.'.php';

        return (string) ob_get_clean();
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
