<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

final class HandlerPlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, payload: string, resource: string, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $handlerClass = $this->inflector->toHandlerClass($params['name']);
        $payloadClass = $this->inflector->toPayloadClass($params['payload']);
        $resourceClass = $this->inflector->toResponseClass($params['resource']);

        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Handler\\PayloadHandler";
        $payloadNamespace = "Semitexa\\Modules\\{$module}\\Application\\Payload\\Request";
        $resourceNamespace = "Semitexa\\Modules\\{$module}\\Application\\Resource\\Response";

        $imports = [
            'use Semitexa\\Core\\Attributes\\AsPayloadHandler;',
            'use Semitexa\\Core\\Contract\\TypedHandlerInterface;',
            "use {$payloadNamespace}\\{$payloadClass};",
            "use {$resourceNamespace}\\{$resourceClass};",
        ];

        sort($imports);

        $template = $this->templateResolver->resolve('handler.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'payloadClass' => $payloadClass,
            'resourceClass' => $resourceClass,
            'className' => $handlerClass,
        ]);

        $filePath = "src/modules/{$module}/Application/Handler/PayloadHandler/{$handlerClass}.php";

        return new GenerationPlan(
            command: 'make:handler',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
