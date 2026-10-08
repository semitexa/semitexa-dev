<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\MakeCrudCommand;

final class MakeCrudResolveModelTest extends TestCase
{
    private string $root;
    private string|false $cwd = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/make-crud-resolve-' . bin2hex(random_bytes(4));
        $db = $this->root . '/src/modules/Shop/src/Application/Db';
        mkdir($db, 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');

        // Same-named files whose class does not autoload: several, so directory order cannot put the real one first by luck.
        foreach (['Legacy', 'Old', 'Archive', 'Backup', 'Draft', 'Attic'] as $dir) {
            mkdir("{$db}/{$dir}");
            file_put_contents("{$db}/{$dir}/CrudResolveProbe.php", "<?php\nnamespace Nowhere\\{$dir};\nfinal class CrudResolveProbe {}\n");
        }
        file_put_contents("{$db}/CrudResolveProbe.php", "<?php\nnamespace " . __NAMESPACE__ . "\\Fixture;\nfinal class CrudResolveProbe {}\n");

        $this->cwd = getcwd();
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_same_named_file_that_does_not_autoload_does_not_hide_the_real_model(): void
    {
        $resolve = new \ReflectionMethod(MakeCrudCommand::class, 'resolveModel');

        self::assertSame(
            Fixture\CrudResolveProbe::class,
            $resolve->invoke(new MakeCrudCommand(), 'CrudResolveProbe', 'Shop'),
        );
    }

    #[Test]
    public function a_short_name_with_no_autoloadable_class_resolves_to_null(): void
    {
        $resolve = new \ReflectionMethod(MakeCrudCommand::class, 'resolveModel');

        self::assertNull($resolve->invoke(new MakeCrudCommand(), 'NoSuchProbe', 'Shop'));
    }
}

namespace Semitexa\Dev\Tests\Unit\Console\Command\Fixture;

final class CrudResolveProbe
{
}
