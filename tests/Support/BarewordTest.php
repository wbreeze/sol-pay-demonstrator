<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A bareword where a string belongs.
 *
 * `['chain' => $x]` written as `[chain => $x]` is **valid PHP**: it is a
 * constant lookup, so `php -l` passes, every editor is happy, and PHP 8 turns
 * it into a fatal `Error: Undefined constant` at the moment that line runs —
 * which, in a route builder, is the first request to one page and no other.
 * It reached the browser on 2026-09-07 as a Slim error page.
 *
 * The cause was mechanical (a shell quoting accident ate the quotes), and so
 * is the check: every `T_STRING` that sits where an array key or an arrow
 * target goes, is not a defined constant, and is not a call or a class
 * reference, is a bareword that should have been a string.
 */
final class BarewordTest extends TestCase
{
    /** @return list<string> */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (['src', 'public', 'config', 'templates', 'bin'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && ($file->getExtension() === 'php' || $file->getPath() === $root.'/bin')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }

    public function testNoArrayKeyIsAnUndefinedConstant(): void
    {
        $problems = [];
        foreach ($this->files() as $file) {
            foreach (self::barewordsIn((string) file_get_contents($file)) as $found) {
                $problems[] = basename($file).':'.$found['line']." — {$found['name']} is a constant here; did you mean '{$found['name']}'?";
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /** @return list<array{name: string, line: int}> */
    private static function barewordsIn(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $out = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            // A call, a class reference, a property or a namespace: not a key.
            if ($next === '(' || $next === '::' || (is_array($next) && $next[0] === T_DOUBLE_COLON)) {
                continue;
            }
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NS_SEPARATOR, T_FUNCTION, T_CLASS, T_CONST, T_NEW, T_USE, T_NAMESPACE, T_INSTANCEOF, T_ENUM, T_INTERFACE, T_TRAIT, T_EXTENDS, T_IMPLEMENTS, T_ATTRIBUTE], true)) {
                continue;
            }
            if (in_array($previous, ['?', ':', '|', '&'], true)) {
                continue; // a type
            }

            // Defined constants are legitimate — SODIUM_CRYPTO_SIGN_BYTES, PHP_VERSION.
            if (defined($token[1]) || function_exists($token[1])) {
                continue;
            }

            $isKey = (is_array($next) && $next[0] === T_DOUBLE_ARROW)
                || ($previous === '[' && $next === ']');

            if ($isKey) {
                $out[] = ['name' => $token[1], 'line' => $token[2]];
            }
        }

        return $out;
    }
}
