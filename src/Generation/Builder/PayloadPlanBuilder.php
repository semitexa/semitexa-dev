<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\TemplateRenderer;

final class PayloadPlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, path: string, method: string, response: string, public: bool, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $studlyName = $this->inflector->toStudly($params['name']);
        $payloadClass = $this->inflector->toPayloadClass($params['name']);
        $responseClass = $this->inflector->toResponseClass($params['response']);
        $module = $this->inflector->toStudly($params['module']);

        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Payload\\Request";
        $responseNamespace = "Semitexa\\Modules\\{$module}\\Application\\Resource\\Response";

        $imports = [
            'use Semitexa\\Core\\Attribute\\AsPayload;',
            'use Semitexa\\Core\\Contract\\ValidatablePayload;',
            'use Semitexa\\Core\\Http\\PayloadValidationResult;',
            "use {$responseNamespace}\\{$responseClass};",
        ];

        $publicEndpoint = '';
        if ($params['public']) {
            $imports[] = 'use Semitexa\\Authorization\\Attributes\\PublicEndpoint;';
            $publicEndpoint = "#[PublicEndpoint]\n";
        }

        sort($imports);

        $template = $this->templateResolver->resolve('payload.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'path' => $params['path'],
            'method' => strtoupper($params['method']),
            'responseClass' => $responseClass,
            'className' => $payloadClass,
            'publicEndpoint' => $publicEndpoint,
        ]);

        $filePath = "src/modules/{$module}/Application/Payload/Request/{$payloadClass}.php";

        return new GenerationPlan(
            command: 'make:payload',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
