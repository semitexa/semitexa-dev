<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;

final class PagePlanBuilder
{
    private readonly PayloadPlanBuilder $payloadBuilder;
    private readonly HandlerPlanBuilder $handlerBuilder;
    private readonly ResourcePlanBuilder $resourceBuilder;
    private readonly TestPlanBuilder $testBuilder;

    public function __construct(
        private readonly NameInflectorInterface $inflector,
        TemplateResolverInterface $templateResolver,
        TemplateRenderer $renderer,
    ) {
        $this->payloadBuilder = new PayloadPlanBuilder($inflector, $templateResolver, $renderer);
        $this->handlerBuilder = new HandlerPlanBuilder($inflector, $templateResolver, $renderer);
        $this->resourceBuilder = new ResourcePlanBuilder($inflector, $templateResolver, $renderer);
        $this->testBuilder = new TestPlanBuilder($inflector, $templateResolver, $renderer);
    }

    /**
     * @param array{module: string, name: string, path: string, method: string, layout?: string, access: string, withAssets: bool, withTest?: bool, dryRun: bool} $params
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
            'access' => $params['access'],
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

        // The all-in-one ships tested code by default: payload contract +
        // handler smoke, matching exactly what this command generates. Opt out
        // with --no-test (withTest=false) when a hand-written test will follow.
        if (($params['withTest'] ?? true) !== false) {
            $testPlan = $this->testBuilder->build([
                'module' => $module,
                'name' => $name,
                'target' => TestPlanBuilder::TARGET_BOTH,
                'dryRun' => false,
            ]);
            $allFiles = array_merge($allFiles, $testPlan->files);
        }

        return new GenerationPlan(
            command: 'make:page',
            files: $allFiles,
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
