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
        self::assertStringContainsString('is not a JSON object of messages: the loader skips the whole file', $problems['src/modules/Shop/Application/View/locales/ro.json'][0] ?? '');
        self::assertStringContainsString('(Syntax error)', $problems['src/modules/Shop/Application/View/locales/uk.json'][0] ?? '');
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
