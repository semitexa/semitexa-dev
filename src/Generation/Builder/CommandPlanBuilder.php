<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

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
        $className = $this->inflector->toStudly($params['name']);
        if (!str_ends_with($className, 'Command')) {
            $className .= 'Command';
        }

        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Command";

        $imports = [
            'use Semitexa\\Core\\Attributes\\AsCommand;',
            'use Semitexa\\Core\\Console\\Command\\BaseCommand;',
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
        ]);

        $filePath = "src/modules/{$module}/Application/Command/{$className}.php";

        return new GenerationPlan(
            command: 'make:command',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
