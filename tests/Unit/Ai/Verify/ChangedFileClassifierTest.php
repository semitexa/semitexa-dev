<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFileClassifier;

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
            'handler' => ['src/modules/Foo/src/Application/Handler/PayloadHandler/X.php', ChangedFile::KIND_HANDLER],
            'listener' => ['src/modules/Foo/src/Application/Handler/DomainListener/Y.php', ChangedFile::KIND_LISTENER],
            'payload' => ['src/modules/Foo/src/Application/Payload/Request/Z.php', ChangedFile::KIND_PAYLOAD],
            'resource' => ['src/modules/Foo/src/Application/Resource/Response/Z.php', ChangedFile::KIND_RESOURCE],
            'service' => ['src/modules/Foo/src/Domain/Service/A.php', ChangedFile::KIND_SERVICE],
            'contract' => ['src/modules/Foo/src/Domain/Contract/B.php', ChangedFile::KIND_CONTRACT],
            'template' => ['src/modules/Foo/Resources/views/page.html.twig', ChangedFile::KIND_TEMPLATE],
            'test_by_dir' => ['tests/Unit/Foo/SomethingTest.php', ChangedFile::KIND_TEST],
            'test_by_suffix' => ['src/modules/Foo/SomethingTest.php', ChangedFile::KIND_TEST],
            'php_other' => ['src/modules/Foo/Random.php', ChangedFile::KIND_PHP_OTHER],
            'non_php' => ['composer.json', ChangedFile::KIND_NON_PHP],

            // Phase 6f.5: fixture / stub / helper / support code under
            // a tests/ tree must NOT be classified as KIND_TEST,
            // because the planner would then try to phpunit-invoke a
            // class that doesn't extend TestCase. The regression that
            // motivated this phase is RecordingAddressesResolver:
            'fixture_under_tests' => [
                'packages/semitexa-core/tests/Unit/Resource/Fixtures/RecordingAddressesResolver.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            'fixture_singular' => [
                'tests/Unit/Foo/Fixture/X.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            'stubs_dir' => [
                'tests/Unit/Foo/Stubs/X.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            'stub_singular' => [
                'tests/Unit/Foo/Stub/Y.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            'support_dir' => [
                'tests/Unit/Foo/Support/Helper.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            'helpers_dir' => [
                'tests/Unit/Foo/Helpers/Helper.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            'traits_dir' => [
                'tests/Unit/Foo/Traits/MakesFoo.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            'doubles_dir' => [
                'tests/Unit/Foo/Doubles/FakeBar.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            // Loose helper directly under tests/ with no Test.php
            // suffix → still fixture-like (e.g. tests/bootstrap.php).
            'loose_helper_under_tests' => [
                'tests/bootstrap.php',
                ChangedFile::KIND_TEST_FIXTURE,
            ],
            // A `*Test.php` file inside a Fixtures/ directory is the
            // ambiguous edge case: filename wins because the planner
            // treats `Test.php` suffix as the strongest signal of a
            // real test class.
            'test_suffix_in_fixture_dir_is_real_test' => [
                'tests/Unit/Foo/Fixtures/EdgeCaseTest.php',
                ChangedFile::KIND_TEST,
            ],
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
