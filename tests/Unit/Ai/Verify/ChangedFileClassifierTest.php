<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
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
            'command_in_module' => [
                'src/modules/Foo/src/Application/Console/Command/SyncCommand.php',
                ChangedFile::KIND_COMMAND,
            ],
            'command_in_package' => [
                'packages/semitexa-mail/src/Application/Console/Command/MailWorkCommand.php',
                ChangedFile::KIND_COMMAND,
            ],
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

    /**
     * Regression pin. Container-managed services live in Application/Service/
     * by MODULE_STRUCTURE convention, but the classifier only knew about
     * Domain/Service/, so every #[AsService] class fell through to
     * KIND_PHP_OTHER. The planner then never selected lint:di, and a
     * service-only diff could verify green at 11/11 with zero skipped while
     * violating DI rules outright. Two commits shipped that way.
     *
     * This asserts only the classification — the kind each path resolves to,
     * which is the half that was wrong. That the planner turns those kinds into
     * lint:di is VerificationPlannerTest's job
     * ({@see VerificationPlannerTest::test_handler_change_selects_handler_and_di_lints_plus_syntax}).
     *
     * @dataProvider containerManagedPathProvider
     */
    public function test_container_managed_paths_get_their_expected_kind(string $path, string $expectedKind): void
    {
        $changed = (new ChangedFileClassifier())->classify($path);

        $this->assertSame($expectedKind, $changed->kind, "path {$path}");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function containerManagedPathProvider(): array
    {
        return [
            'package service' => [
                'packages/semitexa-ssr/src/Application/Service/Component/ComponentCatalog.php',
                ChangedFile::KIND_SERVICE,
            ],
            'nested package service' => [
                'packages/semitexa-ssr/src/Application/Service/Asset/AssetManifestRegistry.php',
                ChangedFile::KIND_SERVICE,
            ],
            'module service' => [
                'src/modules/Demo/Application/Service/Thing.php',
                ChangedFile::KIND_SERVICE,
            ],
            'server lifecycle listener stays a listener' => [
                'packages/semitexa-ssr/src/Application/Service/Server/Lifecycle/WireCoreInstancesListener.php',
                ChangedFile::KIND_LISTENER,
            ],
            'domain service still classifies' => [
                'src/modules/Demo/Domain/Service/Thing.php',
                ChangedFile::KIND_SERVICE,
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

    /**
     * The old name is a CONSTRUCTOR argument. ChangedFile is a readonly class,
     * so the caller that classified a file and then assigned
     * `$file->originalPath` hit a fatal `Cannot modify readonly property` —
     * which is to say `ai:verify --git-ref=<ref>` died outright on any diff
     * containing a rename, silently in the sense that only a rename triggered
     * it. Found while wiring renames through `--dirty` in review of dev#84.
     */
    #[Test]
    public function a_rename_carries_the_name_it_had_without_a_later_assignment(): void
    {
        $file = (new ChangedFileClassifier())->classify(
            'src/modules/Shop/Application/Service/Pricing.php',
            ChangedFile::STATUS_RENAMED,
            'src/modules/Shop/Application/Service/Prices.php',
        );

        self::assertSame(ChangedFile::STATUS_RENAMED, $file->status);
        self::assertSame('src/modules/Shop/Application/Service/Prices.php', $file->originalPath);
        self::assertSame(ChangedFile::KIND_SERVICE, $file->kind, 'the kind still comes from the new path');
    }

    #[Test]
    public function an_ordinary_change_has_no_previous_name(): void
    {
        self::assertNull((new ChangedFileClassifier())->classify('src/a.php')->originalPath);
    }
}
