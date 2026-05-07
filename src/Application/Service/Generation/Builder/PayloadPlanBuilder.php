<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;

final class PayloadPlanBuilder
{
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_PROTECTED = 'protected';
    public const ACCESS_SERVICE = 'service';

    private const ACCESS_TO_ATTRIBUTE = [
        self::ACCESS_PUBLIC    => ['AsPublicPayload', 'Semitexa\\Core\\Attribute\\AsPublicPayload'],
        self::ACCESS_PROTECTED => ['AsProtectedPayload', 'Semitexa\\Authorization\\Attribute\\AsProtectedPayload'],
        self::ACCESS_SERVICE   => ['AsServicePayload',   'Semitexa\\Authorization\\Attribute\\AsServicePayload'],
    ];

    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, path: string, method: string, response: string, access: string, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $access = $params['access'];
        if (!isset(self::ACCESS_TO_ATTRIBUTE[$access])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown payload access type "%s". Expected one of: %s.',
                $access,
                implode(', ', array_keys(self::ACCESS_TO_ATTRIBUTE)),
            ));
        }
        [$accessShort, $accessFqcn] = self::ACCESS_TO_ATTRIBUTE[$access];

        $payloadClass = $this->inflector->toPayloadClass($params['name']);
        $responseClass = $this->inflector->toResponseClass($params['response']);
        $module = $this->inflector->toStudly($params['module']);

        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Payload\\Request";
        $responseNamespace = "Semitexa\\Modules\\{$module}\\Application\\Resource\\Response";

        $imports = [
            "use {$accessFqcn};",
            "use {$responseNamespace}\\{$responseClass};",
        ];
        sort($imports);

        $template = $this->templateResolver->resolve('payload.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'accessAttribute' => $accessShort,
            'path' => $params['path'],
            'method' => strtoupper($params['method']),
            'responseClass' => $responseClass,
            'className' => $payloadClass,
        ], 'make:payload');

        $filePath = "src/modules/{$module}/src/Application/Payload/Request/{$payloadClass}.php";

        return new GenerationPlan(
            command: 'make:payload',
            files: [
                new PlannedFile($filePath, $content, FileType::PhpClass),
            ],
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
