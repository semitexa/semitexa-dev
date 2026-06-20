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

    private const EXPOSE_AS_GRAPHQL_FQCN = 'Semitexa\\Graphql\\Attribute\\ExposeAsGraphql';

    /**
     * @param array{module: string, name: string, path: string, method: string, response: string, access: string, dryRun: bool, graphql?: bool, graphqlField?: ?string} $params
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

        // Opt-in GraphQL exposure. The marker is intentionally bare in the common
        // case: field/rootType/output/list all derive (field from the Payload
        // class name). `graphqlField` is emitted only as an explicit override.
        $graphqlAttribute = '';
        // Fail fast on a meaningless combination rather than silently dropping the
        // override: `graphqlField` only takes effect when GraphQL exposure is on.
        if (empty($params['graphql']) && trim((string) ($params['graphqlField'] ?? '')) !== '') {
            throw new \InvalidArgumentException('`graphqlField` requires `graphql=true`.');
        }
        if (!empty($params['graphql'])) {
            $imports[] = 'use ' . self::EXPOSE_AS_GRAPHQL_FQCN . ';';
            $graphqlAttribute = $this->renderGraphqlAttribute($params['graphqlField'] ?? null);
        }

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
            // Empty when GraphQL is off (placeholder collapses to nothing); the
            // attribute line carries its OWN trailing newline so `class` stays on
            // the next line with no blank gap.
            'graphqlAttribute' => $graphqlAttribute,
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

    /**
     * Render the `#[ExposeAsGraphql]` line (with its own trailing newline). A
     * blank/whitespace-only override field is treated as absent so the marker
     * stays bare and the field derives from the class name.
     */
    private function renderGraphqlAttribute(?string $field): string
    {
        $field = $field !== null ? trim($field) : '';

        if ($field === '') {
            return "#[ExposeAsGraphql]\n";
        }

        return "#[ExposeAsGraphql(field: '" . addslashes($field) . "')]\n";
    }
}
