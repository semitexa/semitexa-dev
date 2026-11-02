<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphModuleCommand;

/**
 * ai:ask module names a local module by the namespace its code declares, as
 * the generators write it (bench-ai-ask-module-namespace): it answered the
 * registry's Semitexa\Modules\<Name> for a module written as App\Modules\<Name>.
 */
final class ModuleNamespaceAnswerTest extends TestCase
{
    private string $root;

    private string|false $cwd = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/module-ns-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/modules/Shop/src/Domain', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->root . '/src/modules/Shop/src/Domain/Order.php', "<?php\n\nnamespace App\\Modules\\Shop\\Domain;\n\nfinal class Order {}\n");
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
    public function a_local_module_answers_with_the_namespace_its_code_declares(): void
    {
        self::assertSame('App\\Modules\\Shop', DevGraphModuleCommand::namespaceOf(['name' => 'Shop', 'type' => 'local', 'namespace' => 'Semitexa\\Modules\\Shop']));
    }

    #[Test]
    public function a_package_module_answers_as_registered(): void
    {
        self::assertSame('Semitexa\\Orm', DevGraphModuleCommand::namespaceOf(['name' => 'orm', 'type' => 'composer', 'namespace' => 'Semitexa\\Orm']));
        self::assertNull(DevGraphModuleCommand::namespaceOf(['name' => 'odd', 'type' => 'vendor']));
    }
}
