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
    public function __construct(private readonly string $templateDir)
    {
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
}
