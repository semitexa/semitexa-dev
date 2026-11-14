<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\LocalePackScan;

/**
 * A locale pack fails silently: JsonFileLoader skips a file that is no JSON object and
 * drops a value that is no string or plural map, and a missing key prints as itself
 * (tk-verify-reads-locale-packs).
 */
final class LocalePackScanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/locale-pack-scan-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    #[Test]
    public function packs_with_the_same_keys_of_strings_and_plural_maps_pass(): void
    {
        $this->pack('src/modules/Shop/src/Application/View/locales/en.json', '{"cart.title": "Cart", "cart.items": {"one": "{{n}} item", "other": "{{n}} items"}}');
        $this->pack('src/modules/Shop/src/Application/View/locales/uk.json', '{"cart.title": "Кошик", "cart.items": {"one": "{{n}} товар", "few": "{{n}} товари", "other": "{{n}} товарів"}}');
        $this->pack('packages/semitexa-cms/src/Application/View/locales/en.json', '{}');

        self::assertSame([], LocalePackScan::problems($this->root));
    }

    #[Test]
    public function a_key_one_language_lacks_is_named_on_that_pack(): void
    {
        $this->pack('src/modules/Shop/src/Application/View/locales/en.json', '{"a": "A", "b": "B"}');
        $this->pack('src/modules/Shop/src/Application/View/locales/ka.json', '{"a": "ა"}');

        $problems = LocalePackScan::problems($this->root);
        self::assertSame(['src/modules/Shop/src/Application/View/locales/ka.json'], array_keys($problems));
        self::assertStringContainsString('lacks 1 key(s) another language in src/modules/Shop/src/Application/View/locales has, first "b"', $problems['src/modules/Shop/src/Application/View/locales/ka.json'][0]);
    }

    #[Test]
    public function what_the_loader_skips_or_drops_is_a_problem(): void
    {
        $this->pack('src/modules/Shop/Application/View/locales/en.json', '{"a": "A", "n": 3, "p": {"one": "x", "lots": "y"}}');
        $this->pack('src/modules/Shop/Application/View/locales/ro.json', '["a", "n", "p"]');
        $this->pack('src/modules/Shop/Application/View/locales/uk.json', '{"a": "A", "n": "3", "p": {"one": "x"}');

        $problems = LocalePackScan::problems($this->root);
        $en = 'src/modules/Shop/Application/View/locales/en.json';
        self::assertSame(['"n" is neither a string nor a plural map (zero, one, two, few, many, other): the loader drops it', '"p" is neither a string nor a plural map (zero, one, two, few, many, other): the loader drops it'], $problems[$en] ?? null);
        self::assertSame(['is not a JSON object of messages: a pack maps each key to its text'], $problems['src/modules/Shop/Application/View/locales/ro.json'] ?? null);
        self::assertStringContainsString('is not valid JSON (Syntax error)', $problems['src/modules/Shop/Application/View/locales/uk.json'][0] ?? '');
    }

    /** Review, dev#261: decoded to arrays, `[]` passed as an empty pack and `{"0": ...}` failed as a list. */
    #[Test]
    public function the_root_is_judged_as_json_wrote_it(): void
    {
        $this->pack('src/modules/Shop/src/Application/View/locales/en.json', '{"0": "Zero", "1": "One"}');
        $this->pack('src/modules/Shop/src/Application/View/locales/uk.json', '{"0": "Нуль", "1": "Один"}');
        $this->pack('src/modules/Cart/src/Application/View/locales/en.json', '[]');

        self::assertSame(
            ['src/modules/Cart/src/Application/View/locales/en.json' => ['is not a JSON object of messages: a pack maps each key to its text']],
            LocalePackScan::problems($this->root),
        );
    }

    /** Independent review, dev#261: a path with glob characters matched nothing, so nothing was read. */
    #[Test]
    public function a_project_whose_path_holds_glob_characters_is_still_read(): void
    {
        $this->root .= '/[prod] {x}';
        $this->pack('src/modules/Shop/src/Application/View/locales/en.json', '{"a": "A", "b": "B"}');
        $this->pack('src/modules/Shop/src/Application/View/locales/uk.json', '{"a": "А"}');

        self::assertSame(['src/modules/Shop/src/Application/View/locales/uk.json'], array_keys(LocalePackScan::problems($this->root)));
        $this->root = dirname($this->root);
    }

    /** Independent review, dev#261: a key the loader accepts is no invalid JSON. */
    #[Test]
    public function a_key_an_object_decode_refuses_is_read_as_the_loader_reads_it(): void
    {
        $this->pack('src/modules/Shop/src/Application/View/locales/en.json', '{"\u0000odd": "x"}');

        self::assertSame([], LocalePackScan::problems($this->root));
    }

    /** Review, dev#261: a directory the lint cannot list fails it, rather than reading as no packs. */
    #[Test]
    public function a_locales_directory_that_cannot_be_listed_fails_the_lint(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // verify:accept-test-change root reads any directory, so the unreadable case cannot be made; the other runs cover it
            self::assertTrue(is_dir(sys_get_temp_dir()));

            return;
        }
        $this->pack('src/modules/Shop/src/Application/View/locales/en.json', '{"a": "A"}');
        chmod($this->root . '/src/modules/Shop/src/Application/View/locales', 0000);
        try {
            $problems = LocalePackScan::problems($this->root);
        } finally {
            chmod($this->root . '/src/modules/Shop/src/Application/View/locales', 0755);
        }

        self::assertSame(['could not be listed: its packs were not read'], $problems['src/modules/Shop/src/Application/View/locales'] ?? null);
    }

    #[Test]
    public function a_locales_directory_the_loader_never_reads_is_not_read(): void
    {
        $this->pack('resources/locales/en.json', '{"a": "A"}');
        $this->pack('resources/locales/uk.json', '[1]');

        self::assertSame([], LocalePackScan::problems($this->root));
    }

    private function pack(string $path, string $json): void
    {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $json);
    }
}
