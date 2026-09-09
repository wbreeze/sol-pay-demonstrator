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
 * 3. **Captured by value where the request mutates by reference.** An arrow
 *    function captures at the moment it is defined and never looks again, so
 *    `static fn (): bool => $stateDone` closes over `false` for the life of the
 *    request while `$read`, which took the same variable as `&$stateDone`,
 *    sets it to true underneath (2026-09-09, the inspector's deferral). This
 *    one errors nowhere: PHPStan level 5 was clean, the suite was green, and
 *    the panel simply deferred on every page including the ones that had
 *    already paid for the read. It surfaced by booting the app and counting
 *    RPC calls.
 *
 * Both — all three — are positional or textual rather than semantic, so the
 * tests are too.
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

    /**
     * No arrow function may close over a variable that something in this file
     * binds by reference.
     *
     * `&$name` in a `use` list is the file saying, in as many words, *this
     * variable is written in one closure and read in another*. `fn` copies its
     * value once, at definition, and the copy never changes — so an arrow
     * function reading such a name is asking a question it can only ever get
     * one answer to. That is precisely how the inspector's deferral came to be
     * permanently on.
     *
     * The rule is deliberately not "was it mutated after this point": that is
     * a question about execution order, and the whole family of faults here is
     * one that execution never complains about. The reference binding is the
     * declared intent, and a by-value read of it is wrong wherever it sits.
     */
    public function testNoArrowFunctionClosesOverAReferenceBoundVariable(): void
    {
        $problems = self::byValueReadsOfReferenceBoundNames($this->source());

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * And the check fails on the broken form, not merely passes on the fixed
     * one — the standard the other structural tests here are held to, made
     * permanent rather than performed once by hand.
     *
     * The first version of `closures()` below skipped every closure in the
     * file and passed by finding nothing. Nothing about a green assertion
     * distinguishes "no faults" from "no scanning", which is why both
     * directions are asserted.
     */
    public function testThatCheckFailsOnTheBrokenFormAndNotOnTheFixedOne(): void
    {
        $broken = <<<'PHP'
            <?php
            $done = false;
            $read = static function () use (&$done): void { $done = true; };
            $alreadyRead = static fn (): bool => $done;
            PHP;

        $fixed = <<<'PHP'
            <?php
            $done = false;
            $read = static function () use (&$done): void { $done = true; };
            $alreadyRead = static function () use (&$done): bool { return $done; };
            PHP;

        // A by-value *parameter* of the same name is not a capture at all, and
        // a name bound by value elsewhere is not this fault.
        $innocent = <<<'PHP'
            <?php
            $done = false;
            $read = static function () use ($done): bool { return $done; };
            $each = static fn (bool $done): bool => $done;
            PHP;

        self::assertNotSame([], self::byValueReadsOfReferenceBoundNames($broken), 'the broken form went unreported');
        self::assertSame([], self::byValueReadsOfReferenceBoundNames($fixed));
        self::assertSame([], self::byValueReadsOfReferenceBoundNames($innocent));
    }

    /**
     * Every arrow function in the source that reads a name some `use` list
     * binds by reference.
     *
     * @return list<string>
     */
    private static function byValueReadsOfReferenceBoundNames(string $source): array
    {
        $tokens = self::significantTokens($source);
        $byReference = self::referenceBoundNames($tokens);

        $problems = [];
        foreach (self::arrowFunctions($tokens) as $arrow) {
            foreach ($arrow['reads'] as $name) {
                if (!in_array($name, $byReference, true)) {
                    continue;
                }
                if (in_array($name, $arrow['params'], true)) {
                    continue;
                }
                $problems[] = "\$$name is bound by reference elsewhere but read by the arrow function"
                    ." at line {$arrow['line']} — `fn` captures by value, so it will never see a change";
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Names any `use` list takes by reference.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    private static function referenceBoundNames(array $tokens): array
    {
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i += 1) {
            $token = $tokens[$i];
            // `use` is also an import and a trait; only a closure's is a call-shaped list.
            if (!is_array($token) || $token[0] !== T_USE || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $depth = 0;
            for ($j = $i + 1; $j < $count; $j += 1) {
                $inner = $tokens[$j];
                if ($inner === '(') {
                    $depth += 1;
                } elseif ($inner === ')') {
                    $depth -= 1;
                    if ($depth === 0) {
                        $i = $j;

                        break;
                    }
                } elseif (self::isAmpersand($inner)) {
                    $next = $tokens[$j + 1] ?? null;
                    if (is_array($next) && $next[0] === T_VARIABLE) {
                        $names[] = substr($next[1], 1);
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every arrow function, with its parameters and every variable its body
     * reads.
     *
     * An arrow body is one expression with no braces to count, so it ends at
     * the first `;` or `,` outside any bracket, or at the bracket that closes
     * around it — `array_map(static fn ($x) => $x + $offset, $rows)` ends at
     * the comma, and a nested one ends at its parent's.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{line: int, params: list<string>, reads: list<string>}>
     */
    private static function arrowFunctions(array $tokens): array
    {
        $out = [];
        $count = count($tokens);

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_FN) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && $tokens[$j] !== '(') {
                $j += 1;
            }
            [$params, $j] = self::variablesUntilClose($tokens, $j);

            // Past the return type, if any, to the arrow itself.
            while ($j < $count && !(is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW)) {
                $j += 1;
            }

            $depth = 0;
            $reads = [];
            for ($b = $j + 1; $b < $count; $b += 1) {
                $inner = $tokens[$b];
                if ($inner === '(' || $inner === '[' || $inner === '{') {
                    $depth += 1;
                } elseif ($inner === ')' || $inner === ']' || $inner === '}') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth -= 1;
                } elseif ($depth === 0 && ($inner === ';' || $inner === ',')) {
                    break;
                } elseif (is_array($inner) && $inner[0] === T_VARIABLE) {
                    $reads[] = substr($inner[1], 1);
                }
            }

            $out[] = [
                'line' => $token[2],
                'params' => $params,
                'reads' => array_values(array_unique($reads)),
            ];
        }

        return $out;
    }

    /**
     * `&` is three tokens since 8.1 depending on what follows it, and a use
     * list can produce either of the two that carry an id.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isAmpersand(array|string $token): bool
    {
        if ($token === '&') {
            return true;
        }

        return is_array($token) && in_array($token[0], [
            T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
            T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
        ], true);
    }

    /**
     * The file without the noise every scan here has to skip.
     *
     * **Whitespace is a token**, and an array one at that. Scanning for the
     * `use` keyword by stepping over non-array tokens therefore stops at the
     * first space and concludes there is no use list — which made the first
     * version of `closures()` skip every closure in the file and pass by
     * finding nothing. Filtering first is what makes any of these scans mean
     * anything. (Found by checking that the test failed on a copy with the bug
     * put back; it did not.)
     *
     * Comments go with it, and that is load-bearing rather than tidy: the
     * docblock above `$stateAlreadyRead` in `public/index.php` quotes the
     * broken arrow form as an illustration of what not to write, and a scan
     * over raw source would report the warning itself as the fault.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function significantTokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
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
        $tokens = self::significantTokens($source);
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
