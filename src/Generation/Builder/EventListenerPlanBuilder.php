<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

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
        ]);

        $filePath = "src/modules/{$module}/Application/Handler/DomainListener/{$className}.php";

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
