<?php

declare(strict_types=1);

namespace Newsprint\Tests\Support;

use Newsprint\Support\View;
use PHPUnit\Framework\TestCase;

/**
 * `View::asset`: a changed file is a new URL, so that a deploy reaches a
 * browser that still holds the previous copy.
 */
final class ViewAssetTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir().'/newsprint-assets-'.bin2hex(random_bytes(4));
        mkdir($this->public.'/assets', 0o700, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->public.'/assets/*') ?: []);
        rmdir($this->public.'/assets');
        rmdir($this->public);
    }

    public function testTheUrlMovesWhenTheFileDoesAndOnlyThen(): void
    {
        file_put_contents($this->public.'/assets/a.js', 'one');
        $first = $this->view()->asset('/assets/a.js');
        self::assertMatchesRegularExpression('~^/assets/a\.js\?v=[0-9a-f]{10}$~', $first);
        self::assertSame($first, $this->view()->asset('/assets/a.js'), 'the same file is the same URL');

        file_put_contents($this->public.'/assets/a.js', 'two');
        self::assertNotSame($first, $this->view()->asset('/assets/a.js'), 'a changed file is a new URL');
    }

    public function testAMissingFileKeepsItsPlainPath(): void
    {
        self::assertSame('/assets/gone.js', $this->view()->asset('/assets/gone.js'));
    }

    /**
     * Every script and stylesheet a template names goes through `asset`, and
     * `swap.js`, which only other modules name, is versioned by the import
     * map. A plain `/assets/` URL in a template is a file that a deploy
     * would not reach.
     */
    public function testNoTemplateNamesAnAssetPlainly(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (glob($root.'/templates/*.php') ?: [] as $template) {
            self::assertDoesNotMatchRegularExpression(
                '~(?:src|href)="/assets/[^"]+\.(?:js|css)"~',
                (string) file_get_contents($template),
                basename($template).' names an asset without a version',
            );
        }

        $layout = (string) file_get_contents($root.'/templates/layout.php');
        foreach (glob($root.'/public/assets/*.js') ?: [] as $script) {
            if (preg_match('~^import .* from \'\./([a-z-]+\.js)\';~m', (string) file_get_contents($script), $found) === 1) {
                self::assertStringContainsString('"/assets/'.$found[1].'": "<?= $view->asset(\'/assets/'.$found[1].'\') ?>"', $layout, $found[1].' is imported, so the import map versions it');
            }
        }
        self::assertLessThan(strpos($layout, '</head>'), strpos($layout, 'type="importmap"'), 'before any module');
    }

    private function view(): View
    {
        return new View(dirname(__DIR__, 2).'/templates', $this->public);
    }
}
