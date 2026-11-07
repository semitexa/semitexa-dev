<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\CssTokenScan;

final class CssTokenScanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-css-tokens-' . uniqid('', true);
        mkdir($this->root . '/src/modules/Shop', 0755, true);
        mkdir($this->root . '/vendor/semitexa/theme/skins', 0755, true);
        file_put_contents($this->root . '/vendor/semitexa/theme/skins/tokens.css', ':root { --ui-surface-page: #fff; }');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->root . '/src/modules/Shop/' . $file, $content);
    }

    public function test_a_token_declared_nowhere_is_named_with_its_file(): void
    {
        $this->write('a.css', 'body { background: var(--ui-color-bg, #fff); }');

        self::assertSame(['src/modules/Shop/a.css' => ['--ui-color-bg']], CssTokenScan::unknown($this->root));
    }

    public function test_a_token_of_a_skin_in_vendor_is_known(): void
    {
        $this->write('a.css', 'body { background: var(--ui-surface-page); }');

        self::assertSame([], CssTokenScan::unknown($this->root));
    }

    public function test_a_token_the_project_declares_itself_is_known(): void
    {
        $this->write('a.css', ':root { --ui-shop-accent: red; } a { color: var(--ui-shop-accent); }');

        self::assertSame([], CssTokenScan::unknown($this->root));
    }

    public function test_a_style_attribute_in_a_template_is_read(): void
    {
        $this->write('a.html.twig', '<div style="color: var(--ui-nope)"></div>');

        self::assertSame(['src/modules/Shop/a.html.twig' => ['--ui-nope']], CssTokenScan::unknown($this->root));
    }

    public function test_a_token_of_another_prefix_is_left_alone(): void
    {
        $this->write('a.css', 'a { color: var(--brand-red); }');

        self::assertSame([], CssTokenScan::unknown($this->root));
    }

    public function test_the_frameworks_own_mistakes_are_not_reported_in_a_project(): void
    {
        file_put_contents($this->root . '/vendor/semitexa/theme/skins/x.css', 'a { color: var(--ui-gone); }');

        self::assertSame([], CssTokenScan::unknown($this->root));
    }
}
