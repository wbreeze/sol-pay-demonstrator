<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Two checks over `public/index.php`, for the same hazard from two directions.
 *
 * `use` binds **by value at closure creation**. That produces two failures
 * that no linter and no unit test can see, and both have reached the browser:
 *
 * 1. **Captured too early.** A route registered above the helper it captures
 *    captures null, and dies with "Value of type null is not callable" the
 *    first time that route is hit (2026-09-07, the meter panel's helper).
 * 2. **Not captured at all.** A helper used in a closure body but missing from
 *    the `use` list is simply undefined inside it — a warning while the page
 *    is still rendering, then the same fatal (2026-09-07, `$payerState` in the
 *    article route, one commit after the first check was written, which is why
 *    there are now two).
 *
 * Both are positional or textual rather than semantic, so the tests are too.
 */
final class FrontControllerTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/public/index.php');
    }

    /**
     * Every variable a top-level closure captures must be assigned earlier in
     * the file than the closure that captures it.
     */
    public function testEveryCapturedVariableIsAssignedBeforeTheClosureThatCapturesIt(): void
    {
        $source = $this->source();
        $assignedAt = self::topLevelAssignments($source);

        preg_match_all('/\\buse\\s*\\(([^)]*)\\)/', $source, $uses, PREG_OFFSET_CAPTURE);

        $problems = [];
        foreach ($uses[1] as [$list, $offset]) {
            preg_match_all('/&?\\$([a-zA-Z_][a-zA-Z0-9_]*)/', $list, $names);
            foreach ($names[1] as $name) {
                if (!isset($assignedAt[$name])) {
                    $problems[] = "\$$name is captured but never assigned";
                } elseif ($assignedAt[$name] > $offset) {
                    $problems[] = "\$$name is captured at byte {$offset} but assigned at byte {$assignedAt[$name]}"
                        .' — the closure will capture null';
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Every top-level helper a closure *uses* must be in its `use` list.
     *
     * Scoped to helper names — the variables assigned at the top level of this
     * file — because those are the ones a closure can silently fail to
     * capture. A name assigned inside the body is a local and fine.
     */
    public function testEveryHelperUsedInAClosureIsCaptured(): void
    {
        $source = $this->source();
        $helpers = array_keys(self::topLevelAssignments($source));

        $problems = [];
        foreach (self::closures($source) as $closure) {
            foreach ($closure['uses'] as $name) {
                if (!in_array($name, $helpers, true)) {
                    continue;
                }
                if (in_array($name, $closure['captured'], true)) {
                    continue;
                }
                if (in_array($name, $closure['params'], true)) {
                    continue;
                }
                if (in_array($name, $closure['locals'], true)) {
                    continue;
                }
                $problems[] = "\$$name is used at line {$closure['line']} but is not in that closure's use list";
            }
        }

        self::assertSame([], array_values(array_unique($problems)), implode("\n", $problems));
    }

    /** @return array<string, int> name => byte offset of its first top-level assignment */
    private static function topLevelAssignments(string $source): array
    {
        $at = [];
        preg_match_all('/^\\$([a-zA-Z_][a-zA-Z0-9_]*)\\s*=/m', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $match) {
            $at[$match[0]] ??= $match[1];
        }

        return $at;
    }

    /**
     * Every closure that has a `use` list, with its parameters, its captures,
     * the variables assigned in its body, and every variable it mentions.
     *
     * A nested closure's body counts as part of its parent's, which is
     * deliberate: an inner closure can only capture what the outer one has.
     *
     * @return list<array{line: int, params: list<string>, captured: list<string>, locals: list<string>, uses: list<string>}>
     */
    private static function closures(string $source): array
    {
        // **Whitespace is a token**, and an array one at that. Scanning for the
        // `use` keyword by stepping over non-array tokens therefore stops at the
        // first space and concludes there is no use list — which made the first
        // version of this test skip every closure in the file and pass by
        // finding nothing. Filtering first is what makes the scan mean
        // anything. (Found by checking that the test failed on a copy with the
        // bug put back; it did not.)
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $out = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            // Parameters: between the first ( and its matching ).
            $j = $i + 1;
            while ($j < count($tokens) && $tokens[$j] !== '(') {
                $j += 1;
            }
            [$params, $j] = self::variablesUntilClose($tokens, $j);

            // A `use` list, if there is one, is the next ( after `use`.
            $captured = [];
            $k = $j + 1;
            while ($k < count($tokens) && !is_array($tokens[$k]) && $tokens[$k] !== '{') {
                $k += 1;
            }
            if (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_USE) {
                while ($k < count($tokens) && $tokens[$k] !== '(') {
                    $k += 1;
                }
                [$captured, $k] = self::variablesUntilClose($tokens, $k);
            } else {
                // No use list: nothing to get wrong.
                continue;
            }

            // The body.
            while ($k < count($tokens) && $tokens[$k] !== '{') {
                $k += 1;
            }
            $depth = 0;
            $uses = [];
            $locals = [];
            for ($b = $k; $b < count($tokens); $b += 1) {
                $t = $tokens[$b];
                if ($t === '{') {
                    $depth += 1;
                } elseif ($t === '}') {
                    $depth -= 1;
                    if ($depth === 0) {
                        break;
                    }
                } elseif (is_array($t) && $t[0] === T_VARIABLE) {
                    $name = substr($t[1], 1);
                    $uses[] = $name;
                    // Assigned here, so it is a local rather than a capture.
                    $next = $tokens[$b + 1] ?? null;
                    $previous = $tokens[$b - 1] ?? null;
                    if ($next === '=' || (is_array($previous) && $previous[0] === T_AS)) {
                        $locals[] = $name;
                    }
                }
            }

            $out[] = [
                'line' => $token[2],
                'params' => $params,
                'captured' => $captured,
                'locals' => array_values(array_unique($locals)),
                'uses' => array_values(array_unique($uses)),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: list<string>, 1: int} the variable names, and where the list closed
     */
    private static function variablesUntilClose(array $tokens, int $open): array
    {
        $names = [];
        $depth = 0;
        for ($i = $open; $i < count($tokens); $i += 1) {
            $t = $tokens[$i];
            if ($t === '(') {
                $depth += 1;
            } elseif ($t === ')') {
                $depth -= 1;
                if ($depth === 0) {
                    return [$names, $i];
                }
            } elseif (is_array($t) && $t[0] === T_VARIABLE) {
                $names[] = substr($t[1], 1);
            }
        }

        return [$names, count($tokens) - 1];
    }
}
