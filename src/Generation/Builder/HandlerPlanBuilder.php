<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Ai\Convention\ConventionStore;
use Semitexa\Dev\Ai\Convention\HandlerInjection;
use Semitexa\Dev\Ai\Convention\ModuleConventions;
use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

final class HandlerPlanBuilder
{
    private const MAX_CONVENTIONAL_INJECTIONS = 3;

    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
        private readonly ?ConventionStore $conventionStore = null,
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

        $injections = $this->resolveConventionalInjections($module);

        $imports = [
            'use Semitexa\\Core\\Attribute\\AsPayloadHandler;',
            'use Semitexa\\Core\\Contract\\TypedHandlerInterface;',
            "use {$payloadNamespace}\\{$payloadClass};",
            "use {$resourceNamespace}\\{$resourceClass};",
        ];

        foreach ($this->buildInjectionImports($injections) as $importLine) {
            $imports[] = $importLine;
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $template = $this->templateResolver->resolve('handler.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'payloadClass' => $payloadClass,
            'resourceClass' => $resourceClass,
            'className' => $handlerClass,
            'injections' => $this->renderInjectionBlock($injections),
        ], 'make:handler');

        $filePath = "src/modules/{$module}/Application/Handler/PayloadHandler/{$handlerClass}.php";

        return new GenerationPlan(
            command: 'make:handler',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }

    /**
     * @return list<HandlerInjection>
     */
    private function resolveConventionalInjections(string $module): array
    {
        if ($this->conventionStore === null) {
            return [];
        }
        $conventions = $this->conventionStore->findForModule($module);
        if (!$conventions instanceof ModuleConventions) {
            return [];
        }
        return array_slice($conventions->recurringHandlerInjections(), 0, self::MAX_CONVENTIONAL_INJECTIONS);
    }

    /**
     * @param list<HandlerInjection> $injections
     * @return list<string>
     */
    private function buildInjectionImports(array $injections): array
    {
        $imports = [];
        foreach ($injections as $hi) {
            $bareType = ltrim($hi->type, '?');
            if (!str_contains($bareType, '\\')) {
                continue;
            }
            $imports[] = "use {$bareType};";
            $imports[] = "use Semitexa\\Core\\Attribute\\{$hi->attribute};";
        }
        return $imports;
    }

    /**
     * @param list<HandlerInjection> $injections
     */
    private function renderInjectionBlock(array $injections): string
    {
        if ($injections === []) {
            return '';
        }
        $lines = [];
        foreach ($injections as $hi) {
            $type = ltrim($hi->type, '?');
            $lines[] = "    #[{$hi->attribute}]";
            $lines[] = "    protected {$hi->shortType} \${$hi->propertyName};";
            $lines[] = '';
        }
        return implode("\n", $lines) . "\n";
    }
}
