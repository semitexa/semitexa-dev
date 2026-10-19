<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Support\ModuleNamespace;

/**
 * A new module is generated under App\Modules. A module that already declares
 * Semitexa\Modules keeps it: a class added there imports its neighbours by the
 * names they declare, and a handler importing App\Modules\X\...\Payload would
 * find the file and not the class.
 */
final class ModuleNamespaceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/module-ns-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/modules', 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function a_module_that_does_not_exist_yet_gets_the_canonical_prefix(): void
    {
        self::assertSame('App\\Modules\\Catalog', ModuleNamespace::of('Catalog', $this->root));
    }

    #[Test]
    public function a_module_with_no_classes_yet_gets_the_canonical_prefix(): void
    {
        mkdir($this->root . '/src/modules/Catalog/src/Domain/Model', 0777, true);
        file_put_contents($this->root . '/src/modules/Catalog/src/Domain/Model/.gitkeep', '');

        self::assertSame('App\\Modules\\Catalog', ModuleNamespace::of('Catalog', $this->root));
    }

    #[Test]
    public function a_module_written_under_semitexa_modules_keeps_its_prefix(): void
    {
        $this->classIn('Playground', 'Application/Payload/Request/ListPayload.php', "namespace Semitexa\\Modules\\Playground\\Application\\Payload\\Request;");

        self::assertSame('Semitexa\\Modules\\Playground', ModuleNamespace::of('Playground', $this->root));
    }

    #[Test]
    public function a_module_written_under_app_modules_keeps_its_prefix(): void
    {
        $this->classIn('Hello', 'Application/Handler/HelloHandler.php', "namespace App\\Modules\\Hello\\Application\\Handler;");

        self::assertSame('App\\Modules\\Hello', ModuleNamespace::of('Hello', $this->root));
    }

    #[Test]
    public function a_namespace_of_another_module_with_the_same_prefix_is_not_read_as_its_own(): void
    {
        // HelloWorld's namespace starts with "Hello" but is not Hello's.
        $this->classIn('Hello', 'Shared/Alias.php', "namespace Semitexa\\Modules\\HelloWorld\\Shared;");

        self::assertSame('App\\Modules\\Hello', ModuleNamespace::of('Hello', $this->root));
    }

    #[Test]
    public function a_namespace_declared_after_a_long_header_is_still_found(): void
    {
        $this->classIn('Catalog', 'Domain/Model/Product.php', '/* ' . str_repeat('license text ', 800) . "*/\n\nnamespace Semitexa\\Modules\\Catalog\\Domain\\Model;");

        self::assertSame('Semitexa\\Modules\\Catalog', ModuleNamespace::of('Catalog', $this->root));
    }

    private function classIn(string $module, string $relative, string $namespace): void
    {
        $path = $this->root . '/src/modules/' . $module . '/src/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n{$namespace}\n\nfinal class X {}\n");
    }
}
