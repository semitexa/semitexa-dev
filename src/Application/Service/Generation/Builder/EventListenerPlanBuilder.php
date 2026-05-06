<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;

final class EventListenerPlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, event: string, execution: string, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $className = $this->inflector->toStudly($params['name']);
        [$eventImport, $eventClass] = $this->resolveEventReference($module, $params['event']);
        $execution = $this->inflector->toStudly($params['execution']);

        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Handler\\DomainListener";

        $imports = [
            'use Semitexa\\Core\\Attribute\\AsEventListener;',
            'use Semitexa\\Core\\Event\\EventExecution;',
            "use {$eventImport};",
        ];

        sort($imports);

        $template = $this->templateResolver->resolve('event-listener.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'eventClass' => $eventClass,
            'execution' => $execution,
            'className' => $className,
        ], 'make:event-listener');

        $filePath = "src/modules/{$module}/src/Application/Handler/DomainListener/{$className}.php";

        return new GenerationPlan(
            command: 'make:event-listener',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveEventReference(string $module, string $event): array
    {
        $event = trim($event);

        if (str_contains($event, '\\')) {
            $eventImport = ltrim($event, '\\');
            $parts = explode('\\', $eventImport);

            return [$eventImport, (string) end($parts)];
        }

        $eventClass = $this->inflector->toStudly($event);

        return ["Semitexa\\Modules\\{$module}\\Domain\\Event\\{$eventClass}", $eventClass];
    }
}
