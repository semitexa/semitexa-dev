<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

final class ContractPlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, implementation: string, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $name = $this->inflector->toStudly($params['name']);
        $implName = $this->inflector->toStudly($params['implementation']);

        $interfaceClass = $name;
        if (!str_ends_with($interfaceClass, 'Interface')) {
            $interfaceClass .= 'Interface';
        }

        $contractNamespace = "Semitexa\\Modules\\{$module}\\Domain\\Contract";
        $implNamespace = "Semitexa\\Modules\\{$module}\\Domain\\Service";

        // Interface file
        $interfaceTemplate = $this->templateResolver->resolve('contract-interface.php.tpl');
        $interfaceContent = $this->renderer->render($interfaceTemplate, [
            'namespace' => $contractNamespace,
            'className' => $interfaceClass,
        ]);

        // Implementation file
        $implImports = [
            'use Semitexa\\Core\\Attributes\\SatisfiesServiceContract;',
            "use {$contractNamespace}\\{$interfaceClass};",
        ];
        sort($implImports);

        $implTemplate = $this->templateResolver->resolve('contract-implementation.php.tpl');
        $implContent = $this->renderer->render($implTemplate, [
            'namespace' => $implNamespace,
            'imports' => implode("\n", $implImports),
            'interfaceClass' => $interfaceClass,
            'className' => $implName,
        ]);

        $interfacePath = "src/modules/{$module}/Domain/Contract/{$interfaceClass}.php";
        $implPath = "src/modules/{$module}/Domain/Service/{$implName}.php";

        return new GenerationPlan(
            command: 'make:contract',
            files: [
                new PlannedFile($interfacePath, $interfaceContent, FileType::PhpClass),
                new PlannedFile($implPath, $implContent, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
