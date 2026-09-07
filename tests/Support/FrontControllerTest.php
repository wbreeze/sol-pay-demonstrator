<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * One check over `public/index.php`, for one mistake that costs an afternoon.
 *
 * `use` binds by **value at closure creation**, not at call time. A route
 * registered above the helper it captures therefore captures nothing: PHP
 * warns "Undefined variable" while the file is still loading — which is easy
 * to miss on a page that renders anyway — and then dies with "Value of type
 * null is not callable" the moment that route is hit. It happened on
 * 2026-09-07: the meter panel's helper was defined below the article route
 * that captures it, and the article page was the only page that showed it.
 *
 * Nothing else catches this. `php -l` is happy, every unit test is happy, and
 * the routes that do not capture the missing helper serve perfectly. The
 * failure is positional, so the test is positional too: every variable a
 * top-level closure captures must be assigned earlier in the file than the
 * closure that captures it.
 */
final class FrontControllerTest extends TestCase
{
    public function testEveryCapturedVariableIsAssignedBeforeTheClosureThatCapturesIt(): void
    {
        $path = dirname(__DIR__, 2).'/public/index.php';
        $source = (string) file_get_contents($path);

        // Where each top-level assignment happens. Anchored to the start of a
        // line, which is what "top-level" looks like in this file.
        $assignedAt = [];
        preg_match_all('/^\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=/m', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $match) {
            $name = $match[0];
            $assignedAt[$name] ??= $match[1];
        }

        preg_match_all('/\buse\s*\(([^)]*)\)/', $source, $uses, PREG_OFFSET_CAPTURE);

        $problems = [];
        foreach ($uses[1] as $use) {
            [$list, $offset] = $use;
            preg_match_all('/&?\$([a-zA-Z_][a-zA-Z0-9_]*)/', $list, $names);
            foreach ($names[1] as $name) {
                if (!isset($assignedAt[$name])) {
                    $problems[] = "\${$name} is captured but never assigned";

                    continue;
                }
                if ($assignedAt[$name] > $offset) {
                    $problems[] = "\${$name} is captured at byte {$offset} but assigned at byte {$assignedAt[$name]}"
                        .' — the closure will capture null';
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }
}
