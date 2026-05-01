<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Similarity;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Similarity\SimilarityIndexBuilder;

class SimilarityIndexBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-similarity-index-' . uniqid();
        mkdir($this->root . '/src/modules/Foo/Application/Handler/PayloadHandler', 0755, true);
        mkdir($this->root . '/src/modules/Foo/Application/Handler/DomainListener', 0755, true);
        mkdir($this->root . '/src/modules/Foo/Application/Payload/Request', 0755, true);
        mkdir($this->root . '/src/modules/Foo/Application/Resource/Response', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function test_indexes_each_kind_under_module(): void
    {
        file_put_contents($this->root . '/src/modules/Foo/Application/Payload/Request/GetThingPayload.php', <<<PHP
<?php
namespace Semitexa\\Modules\\Foo\\Application\\Payload\\Request;
use Semitexa\\Core\\Attribute\\AsPayload;
#[AsPayload(path: '/foo/{id}', methods: ['GET'])]
final class GetThingPayload {}
PHP);
        file_put_contents($this->root . '/src/modules/Foo/Application/Resource/Response/GetThingResponse.php', <<<PHP
<?php
namespace Semitexa\\Modules\\Foo\\Application\\Resource\\Response;
final class GetThingResponse {}
PHP);
        file_put_contents($this->root . '/src/modules/Foo/Application/Handler/PayloadHandler/GetThingHandler.php', <<<PHP
<?php
namespace Semitexa\\Modules\\Foo\\Application\\Handler\\PayloadHandler;
use Semitexa\\Core\\Attribute\\AsPayloadHandler;
use Semitexa\\Modules\\Foo\\Application\\Payload\\Request\\GetThingPayload;
use Semitexa\\Modules\\Foo\\Application\\Resource\\Response\\GetThingResponse;
#[AsPayloadHandler(payload: GetThingPayload::class, resource: GetThingResponse::class)]
final class GetThingHandler {}
PHP);
        file_put_contents($this->root . '/src/modules/Foo/Application/Handler/DomainListener/OnUserRegistered.php', <<<PHP
<?php
namespace Semitexa\\Modules\\Foo\\Application\\Handler\\DomainListener;
use Semitexa\\Core\\Attribute\\AsEventListener;
use Semitexa\\Modules\\Bar\\Domain\\Event\\UserRegistered;
#[AsEventListener(event: UserRegistered::class)]
final class OnUserRegistered {}
PHP);

        $index = (new SimilarityIndexBuilder($this->root, ['src/modules']))->build();

        $payloads = $index->ofKindInModule('payload', 'Foo');
        $this->assertCount(1, $payloads);
        $this->assertSame('GetThingPayload', $payloads[0]->className);
        $this->assertSame('Semitexa\\Modules\\Foo\\Application\\Payload\\Request\\GetThingPayload', $payloads[0]->fqcn);
        $this->assertSame('/foo/{id}', $payloads[0]->extras['route_path']);
        $this->assertSame('GET', $payloads[0]->extras['route_method']);

        $handlers = $index->ofKindInModule('handler', 'Foo');
        $this->assertCount(1, $handlers);
        $this->assertSame('Semitexa\\Modules\\Foo\\Application\\Payload\\Request\\GetThingPayload', $handlers[0]->extras['payload_fqcn']);
        $this->assertSame('Semitexa\\Modules\\Foo\\Application\\Resource\\Response\\GetThingResponse', $handlers[0]->extras['resource_fqcn']);

        $listeners = $index->ofKindInModule('listener', 'Foo');
        $this->assertCount(1, $listeners);
        $this->assertSame('Semitexa\\Modules\\Bar\\Domain\\Event\\UserRegistered', $listeners[0]->extras['event_fqcn']);

        $this->assertCount(1, $index->ofKindInModule('resource', 'Foo'));
    }

    public function test_ignores_files_outside_known_fragments(): void
    {
        mkdir($this->root . '/src/modules/Foo/Domain/Service', 0755, true);
        file_put_contents($this->root . '/src/modules/Foo/Domain/Service/Whatever.php', "<?php\nclass Whatever {}\n");

        $index = (new SimilarityIndexBuilder($this->root, ['src/modules']))->build();
        $this->assertSame([], $index->all());
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
