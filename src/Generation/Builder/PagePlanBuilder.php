<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

final class PagePlanBuilder
{
    private readonly PayloadPlanBuilder $payloadBuilder;
    private readonly HandlerPlanBuilder $handlerBuilder;
    private readonly ResourcePlanBuilder $resourceBuilder;

    public function __construct(
        private readonly NameInflectorInterface $inflector,
        TemplateResolverInterface $templateResolver,
        TemplateRenderer $renderer,
    ) {
        $this->payloadBuilder = new PayloadPlanBuilder($inflector, $templateResolver, $renderer);
        $this->handlerBuilder = new HandlerPlanBuilder($inflector, $templateResolver, $renderer);
        $this->resourceBuilder = new ResourcePlanBuilder($inflector, $templateResolver, $renderer);
    }

    /**
     * @param array{module: string, name: string, path: string, method: string, layout?: string, public: bool, withAssets: bool, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $name = $params['name'];
        $module = $params['module'];
        $kebabName = $this->inflector->toKebab($name);

        $payloadPlan = $this->payloadBuilder->build([
            'module' => $module,
            'name' => $name,
            'path' => $params['path'],
            'method' => $params['method'],
            'response' => $name,
            'public' => $params['public'],
            'dryRun' => false,
        ]);

        $handlerPlan = $this->handlerBuilder->build([
            'module' => $module,
            'name' => $name,
            'payload' => $name,
            'resource' => $name,
            'dryRun' => false,
        ]);

        $resourcePlan = $this->resourceBuilder->build([
            'module' => $module,
            'name' => $name,
            'handle' => $kebabName,
            'template' => null,
            'withTemplate' => true,
            'withAssets' => $params['withAssets'],
            'dryRun' => false,
        ]);

        $allFiles = array_merge(
            $payloadPlan->files,
            $handlerPlan->files,
            $resourcePlan->files,
        );

        return new GenerationPlan(
            command: 'make:page',
            files: $allFiles,
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
