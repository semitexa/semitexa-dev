<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;

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
        ], 'make:service');

        $filePath = "src/modules/{$module}/src/Domain/Service/{$className}.php";

        return new GenerationPlan(
            command: 'make:service',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
