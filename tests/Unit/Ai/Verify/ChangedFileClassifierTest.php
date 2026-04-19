<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Verify\ChangedFile;
use Semitexa\Dev\Ai\Verify\ChangedFileClassifier;

class ChangedFileClassifierTest extends TestCase
{
    /**
     * @dataProvider classificationProvider
     */
    public function test_classifies_path_to_kind(string $path, string $expectedKind): void
    {
        $changed = (new ChangedFileClassifier())->classify($path);
        $this->assertSame($expectedKind, $changed->kind, "path {$path}");
    }

    public static function classificationProvider(): array
    {
        return [
            'handler' => ['src/modules/Foo/Application/Handler/PayloadHandler/X.php', ChangedFile::KIND_HANDLER],
            'listener' => ['src/modules/Foo/Application/Handler/DomainListener/Y.php', ChangedFile::KIND_LISTENER],
            'payload' => ['src/modules/Foo/Application/Payload/Request/Z.php', ChangedFile::KIND_PAYLOAD],
            'resource' => ['src/modules/Foo/Application/Resource/Response/Z.php', ChangedFile::KIND_RESOURCE],
            'service' => ['src/modules/Foo/Domain/Service/A.php', ChangedFile::KIND_SERVICE],
            'contract' => ['src/modules/Foo/Domain/Contract/B.php', ChangedFile::KIND_CONTRACT],
            'template' => ['src/modules/Foo/Resources/views/page.html.twig', ChangedFile::KIND_TEMPLATE],
            'test_by_dir' => ['tests/Unit/Foo/SomethingTest.php', ChangedFile::KIND_TEST],
            'test_by_suffix' => ['src/modules/Foo/SomethingTest.php', ChangedFile::KIND_TEST],
            'php_other' => ['src/modules/Foo/Random.php', ChangedFile::KIND_PHP_OTHER],
            'non_php' => ['composer.json', ChangedFile::KIND_NON_PHP],
        ];
    }

    public function test_default_status_is_modified(): void
    {
        $changed = (new ChangedFileClassifier())->classify('src/anything.php');
        $this->assertSame(ChangedFile::STATUS_MODIFIED, $changed->status);
    }

    public function test_status_is_carried_through(): void
    {
        $changed = (new ChangedFileClassifier())->classify('src/anything.php', ChangedFile::STATUS_DELETED);
        $this->assertSame(ChangedFile::STATUS_DELETED, $changed->status);
    }
}
