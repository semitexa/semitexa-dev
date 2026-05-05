<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\CommandPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;

/**
 * The make:command generator must emit a class under
 * Application/Console/Command/ — the canonical, validator-approved location
 * — with a namespace that matches the path. The two halves were silently out
 * of sync before this fix; the test keeps them lockstep.
 */
final class CommandPlanBuilderTest extends TestCase
{
    private CommandPlanBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CommandPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );
    }

    #[Test]
    public function generated_path_lives_under_application_console_command(): void
    {
        $plan = $this->builder->build([
            'module' => 'Catalog',
            'name' => 'Reindex',
            'commandName' => 'catalog:reindex',
            'description' => 'Rebuild the catalog search index.',
            'dryRun' => true,
        ]);

        self::assertCount(1, $plan->files);
        self::assertSame(
            'src/modules/Catalog/Application/Console/Command/ReindexCommand.php',
            $plan->files[0]->path,
        );
    }

    #[Test]
    public function generated_namespace_matches_the_canonical_path(): void
    {
        $plan = $this->builder->build([
            'module' => 'Catalog',
            'name' => 'Reindex',
            'commandName' => 'catalog:reindex',
            'description' => 'Rebuild the catalog search index.',
            'dryRun' => true,
        ]);

        $content = $plan->files[0]->content;
        self::assertStringContainsString(
            'namespace Semitexa\Modules\Catalog\Application\Console\Command;',
            $content,
        );
        self::assertStringNotContainsString(
            'namespace Semitexa\Modules\Catalog\Application\Command;',
            $content,
            'generator must not emit the deprecated Application\\Command namespace',
        );
    }

    #[Test]
    public function generated_imports_point_at_real_core_classes(): void
    {
        $plan = $this->builder->build([
            'module' => 'Catalog',
            'name' => 'Reindex',
            'commandName' => 'catalog:reindex',
            'description' => 'Rebuild.',
            'dryRun' => true,
        ]);

        $content = $plan->files[0]->content;

        // AsCommand lives in Semitexa\Core\Attribute\ (singular), not Attributes\.
        // BaseCommand lives in Semitexa\Core\Console\, not Semitexa\Core\Console\Command\.
        // The generator was emitting both wrong before this fix; the file would
        // not even autoload.
        self::assertStringContainsString('use Semitexa\Core\Attribute\AsCommand;', $content);
        self::assertStringContainsString('use Semitexa\Core\Console\BaseCommand;', $content);
        self::assertStringNotContainsString('Semitexa\Core\Attributes\AsCommand', $content);
        self::assertStringNotContainsString('Semitexa\Core\Console\Command\BaseCommand', $content);
    }

    #[Test]
    public function command_suffix_added_when_missing(): void
    {
        $plan = $this->builder->build([
            'module' => 'Catalog',
            'name' => 'Sync',
            'commandName' => 'catalog:sync',
            'description' => 'Sync.',
            'dryRun' => true,
        ]);
        self::assertStringEndsWith('SyncCommand.php', $plan->files[0]->path);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function nameMatrix(): array
    {
        return [
            // separator-driven: kebab/snake/space/SCREAMING normalize cleanly
            ['sync',                   'SyncCommand'],
            ['sync-command',           'SyncCommand'],
            ['sync_command',           'SyncCommand'],
            ['SYNC_COMMAND',           'SyncCommand'],
            ['user-import',            'UserImportCommand'],
            ['user-import-command',    'UserImportCommand'],

            // already-PascalCase / camelCase preserved (regression — was mangled)
            ['syncCommand',            'SyncCommand'],
            ['SyncCommand',            'SyncCommand'],
            ['UserImport',             'UserImportCommand'],
            ['UserImportCommand',      'UserImportCommand'],
            ['ClearCacheCommand',      'ClearCacheCommand'],
            ['GenerateOpenApiCommand', 'GenerateOpenApiCommand'],
            ['XMLExportCommand',       'XMLExportCommand'],

            // case-insensitive suffix dedup — never CommandCommand
            ['synccommand',            'SyncCommand'],
            // All-caps acronym preserved (same rule that keeps
            // XMLExportCommand intact). Either SYNCCommand or SyncCommand
            // satisfies the "no CommandCommand" requirement — see task §3.
            ['SYNCCOMMAND',            'SYNCCommand'],
        ];
    }

    /**
     * @dataProvider nameMatrix
     */
    #[DataProvider('nameMatrix')]
    public function test_class_name_path_and_namespace_stay_in_lockstep(string $input, string $expected): void
    {
        $plan = $this->builder->build([
            'module' => 'Catalog',
            'name' => $input,
            'commandName' => 'catalog:probe',
            'description' => 'Probe.',
            'dryRun' => true,
        ]);

        $file = $plan->files[0];

        // 1. File path matches the canonical class name
        self::assertSame(
            "src/modules/Catalog/Application/Console/Command/{$expected}.php",
            $file->path,
            "input '{$input}' must produce file path ending in '{$expected}.php'",
        );

        // 2. Class declaration matches the file path
        self::assertStringContainsString("final class {$expected} extends BaseCommand", $file->content);

        // 3. Namespace stays canonical
        self::assertStringContainsString(
            'namespace Semitexa\Modules\Catalog\Application\Console\Command;',
            $file->content,
        );

        // 4. Hard guard — no doubled suffix anywhere in the rendered file
        self::assertStringNotContainsString('CommandCommand', $file->content);
        self::assertStringNotContainsString('CommandCommand', $file->path);
    }
}
