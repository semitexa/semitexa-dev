<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Convention;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Convention\ConventionMiner;
use Semitexa\Dev\Application\Service\Ai\Convention\HandlerInjection;

class ConventionMinerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-miner-test-' . uniqid();
        mkdir($this->projectRoot . '/src/modules/Foo/Application/Handler/PayloadHandler', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectRoot);
    }

    public function test_aggregates_recurring_injection_across_handlers(): void
    {
        $this->writeHandler('AlphaHandler', 'AlphaPayload', 'AlphaResponse');
        $this->writeHandler('BetaHandler', 'BetaPayload', 'BetaResponse');
        $this->writeHandler('GammaHandler', 'GammaPayload', 'GammaResponse');

        $miner = new ConventionMiner($this->projectRoot, ['src/modules']);
        $byModule = $miner->mineAll();

        $this->assertArrayHasKey('Foo', $byModule);
        $foo = $byModule['Foo'];
        $this->assertSame(3, $foo->handlers_sampled);

        $recurring = $foo->recurringHandlerInjections();
        $this->assertCount(1, $recurring);

        /** @var HandlerInjection $injection */
        $injection = $recurring[0];
        $this->assertSame('Acme\\Service\\CatalogService', $injection->type);
        $this->assertSame('CatalogService', $injection->shortType);
        $this->assertSame('InjectAsReadonly', $injection->attribute);
        $this->assertSame('catalog', $injection->propertyName);
        $this->assertSame(3, $injection->frequency);
    }

    public function test_single_occurrence_is_not_a_convention(): void
    {
        $this->writeHandler('SoloHandler', 'SoloPayload', 'SoloResponse');

        $miner = new ConventionMiner($this->projectRoot, ['src/modules']);
        $foo = $miner->mineAll()['Foo'];

        $this->assertSame(1, $foo->handlers_sampled);
        $this->assertCount(1, $foo->handler_injections);
        $this->assertSame([], $foo->recurringHandlerInjections());
    }

    public function test_ignores_files_outside_handler_layout(): void
    {
        mkdir($this->projectRoot . '/src/modules/Bar/Application/Service', 0755, true);
        file_put_contents(
            $this->projectRoot . '/src/modules/Bar/Application/Service/Whatever.php',
            "<?php\nnamespace Semitexa\\Modules\\Bar\\Application\\Service;\nuse Semitexa\\Core\\Attribute\\InjectAsReadonly;\nuse Acme\\Service\\CatalogService;\nclass Whatever { #[InjectAsReadonly] protected CatalogService \$catalog; }\n",
        );

        $byModule = (new ConventionMiner($this->projectRoot, ['src/modules']))->mineAll();
        $this->assertArrayNotHasKey('Bar', $byModule);
    }

    private function writeHandler(string $class, string $payload, string $response): void
    {
        $php = <<<PHP
<?php

declare(strict_types=1);

namespace Semitexa\\Modules\\Foo\\Application\\Handler\\PayloadHandler;

use Acme\\Service\\CatalogService;
use Semitexa\\Core\\Attribute\\AsPayloadHandler;
use Semitexa\\Core\\Attribute\\InjectAsReadonly;
use Semitexa\\Core\\Contract\\TypedHandlerInterface;
use Semitexa\\Modules\\Foo\\Application\\Payload\\Request\\{$payload};
use Semitexa\\Modules\\Foo\\Application\\Resource\\Response\\{$response};

#[AsPayloadHandler(payload: {$payload}::class, resource: {$response}::class)]
final class {$class} implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected CatalogService \$catalog;

    public function handle({$payload} \$payload, {$response} \$resource): {$response}
    {
        return \$resource;
    }
}
PHP;
        file_put_contents(
            $this->projectRoot . "/src/modules/Foo/Application/Handler/PayloadHandler/{$class}.php",
            $php,
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
