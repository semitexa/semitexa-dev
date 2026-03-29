<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

final class ServicePlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $className = $this->inflector->toStudly($params['name']);

        $namespace = "Semitexa\\Modules\\{$module}\\Domain\\Service";

        $imports = [
            'use Semitexa\\Core\\Attributes\\AsService;',
        ];

        sort($imports);

        $template = $this->templateResolver->resolve('service.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'className' => $className,
        ]);

        $filePath = "src/modules/{$module}/Domain/Service/{$className}.php";

        return new GenerationPlan(
            command: 'make:service',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
