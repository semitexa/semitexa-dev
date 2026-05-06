<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;

final class CommandPlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, commandName: string, description: string, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        // Single source of truth for command-class normalization. Handles
        // every input shape (sync, sync-command, sync_command, syncCommand,
        // SyncCommand, SYNC_COMMAND, synccommand) → SyncCommand, with no
        // CommandCommand duplication. See NameInflector::withSuffix.
        $className = $this->inflector->toCommandClass($params['name']);

        // Canonical: Application/Console/Command/ — required by the
        // module-structure validator (see packages/semitexa-docs/docs/MODULE_STRUCTURE.md).
        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Console\\Command";

        $imports = [
            'use Semitexa\\Core\\Attribute\\AsCommand;',
            'use Semitexa\\Core\\Console\\BaseCommand;',
            'use Symfony\\Component\\Console\\Input\\InputInterface;',
            'use Symfony\\Component\\Console\\Output\\OutputInterface;',
            'use Symfony\\Component\\Console\\Style\\SymfonyStyle;',
        ];

        sort($imports);

        $template = $this->templateResolver->resolve('command.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'commandName' => $params['commandName'],
            'description' => $params['description'],
            'className' => $className,
        ], 'make:command');

        $filePath = "src/modules/{$module}/Application/Console/Command/{$className}.php";

        return new GenerationPlan(
            command: 'make:command',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
