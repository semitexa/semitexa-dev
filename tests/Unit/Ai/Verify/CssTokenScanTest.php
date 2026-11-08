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

    /** REGRESSION (review dev#216): a note in a comment declared the token it mentioned. */
    public function test_a_comment_declares_nothing(): void
    {
        $this->write('a.css', '/* --ui-foo: removed */ a { color: var(--ui-foo, red); }');
        $this->write('b.html.twig', '{# --ui-bar: later #}<p style="color: var(--ui-bar)"></p>');

        self::assertSame(['src/modules/Shop/a.css' => ['--ui-foo'], 'src/modules/Shop/b.html.twig' => ['--ui-bar']], CssTokenScan::unknown($this->root));
    }

    /** REGRESSION (review dev#216): a declared prefix hid the full name it is part of. */
    public function test_a_declared_prefix_does_not_hide_a_longer_or_other_case_name(): void
    {
        $this->write('a.css', ':root { --ui-a: 1px; } a { margin: var(--ui-a_b); padding: var(--ui-A); }');

        self::assertSame(['src/modules/Shop/a.css' => ['--ui-a_b', '--ui-A']], CssTokenScan::unknown($this->root));
    }

    public function test_a_file_it_cannot_read_fails_the_scan(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads a file without read permission');
        }
        $this->write('a.css', 'a { color: var(--ui-surface-page); }');
        chmod($this->root . '/src/modules/Shop/a.css', 0o000);

        $this->expectExceptionMessage('cannot read src/modules/Shop/a.css');
        try {
            CssTokenScan::unknown($this->root);
        } finally {
            chmod($this->root . '/src/modules/Shop/a.css', 0o644);
        }
    }

    public function test_a_package_declares_for_the_project_and_its_own_uses_are_read(): void
    {
        mkdir($this->root . '/packages/semitexa-ui/css', 0755, true);
        file_put_contents($this->root . '/packages/semitexa-ui/css/ui.css', ':root { --ui-ring: blue; } a { outline: var(--ui-gone); }');
        $this->write('a.css', 'a { outline-color: var(--ui-ring); }');

        self::assertSame(['packages/semitexa-ui/css/ui.css' => ['--ui-gone']], CssTokenScan::unknown($this->root));
    }

    public function test_node_modules_and_a_nested_vendor_are_not_read(): void
    {
        foreach (['src/modules/Shop/node_modules/x', 'src/modules/Shop/vendor/y'] as $dir) {
            mkdir($this->root . '/' . $dir, 0755, true);
            file_put_contents($this->root . '/' . $dir . '/z.css', ':root { --ui-hidden: 1px; } a { color: var(--ui-unseen); }');
        }
        $this->write('a.css', 'a { margin: var(--ui-hidden); }');

        self::assertSame(['src/modules/Shop/a.css' => ['--ui-hidden']], CssTokenScan::unknown($this->root));
    }

    /** REGRESSION (review dev#216): a comment opener inside a string hid everything up to the next closer. */
    public function test_a_comment_marker_inside_a_string_opens_no_comment(): void
    {
        $this->write('a.css', 'a::before { content: "/*"; } b { color: var(--ui-missing); } c::after { content: "*/"; }');

        self::assertSame(['src/modules/Shop/a.css' => ['--ui-missing']], CssTokenScan::unknown($this->root));
    }

    /** REGRESSION (review dev#216): prose in a template ("--ui-foo: removed") counted as a declaration. */
    public function test_a_template_declares_only_in_its_css(): void
    {
        $this->write('a.html.twig', '<p>--ui-foo: removed</p><style>:root { --ui-bar: red; }</style><i style="--ui-baz: 1px"></i><b style="color: var(--ui-foo)"></b>');
        $this->write('b.css', 'a { color: var(--ui-bar); margin: var(--ui-baz); }');

        self::assertSame(['src/modules/Shop/a.html.twig' => ['--ui-foo']], CssTokenScan::unknown($this->root));
    }

    /** REGRESSION (independent review): an apostrophe in prose opened a "string" that kept comments. */
    public function test_an_apostrophe_in_template_prose_changes_nothing(): void
    {
        $this->write('a.html.twig', "<p>don't</p>{# var(--ui-gone) #}<style>/* --ui-x: 1 */ a { color: var(--ui-x); }</style><p>it's</p>");

        self::assertSame(['src/modules/Shop/a.html.twig' => ['--ui-x']], CssTokenScan::unknown($this->root));
    }

    /** REGRESSION (independent review): `{#` in minified CSS, or an unclosed `/*`, swallowed the rest of the file. */
    public function test_twig_comments_belong_to_templates_and_a_comment_must_close(): void
    {
        $this->write('a.css', '@media(max-width:1px){#foo{--ui-x:1}}b{--ui-y:2} i { background: url(/img/*.png); }');
        $this->write('b.css', 'a { margin: var(--ui-x); padding: var(--ui-y); }');

        self::assertSame([], CssTokenScan::unknown($this->root));
    }

    /** REGRESSION (independent review): a double-quoted style attribute was cut at a quote inside Twig. */
    public function test_a_style_attribute_may_hold_twig_with_quotes(): void
    {
        $this->write('a.html.twig', '<i style="{{ c ? "a" : "b" }}; --ui-x: 1"></i>');
        $this->write('b.css', 'a { margin: var(--ui-x); }');

        self::assertSame([], CssTokenScan::unknown($this->root));
    }
}
