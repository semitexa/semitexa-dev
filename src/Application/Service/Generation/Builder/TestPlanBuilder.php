<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;

/**
 * Plans PHPUnit test scaffolds for a generated payload and/or handler.
 *
 * The payload contract test boots the shared semitexa-testing runtime
 * (Integration) and runs the strategies declared via #[TestablePayload]; the
 * handler test is a fast instantiation smoke (Unit). Files land under the
 * canonical PascalCase tests/{Integration,Unit}/ categories with the
 * App\Tests\Modules\<Module>\… namespace the bootstrap PSR-4 maps.
 */
final class TestPlanBuilder
{
    public const TARGET_PAYLOAD = 'payload';
    public const TARGET_HANDLER = 'handler';
    public const TARGET_BOTH = 'both';

    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, target?: string, dryRun?: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $target = $params['target'] ?? self::TARGET_BOTH;

        $files = [];
        if ($target === self::TARGET_PAYLOAD || $target === self::TARGET_BOTH) {
            $files[] = $this->payloadTest($module, $params['name']);
        }
        if ($target === self::TARGET_HANDLER || $target === self::TARGET_BOTH) {
            $files[] = $this->handlerTest($module, $params['name']);
        }

        return new GenerationPlan(
            command: 'make:test',
            files: $files,
            dryRun: $params['dryRun'] ?? false,
        );
    }

    private function payloadTest(string $module, string $name): PlannedFile
    {
        $payloadClass = $this->inflector->toPayloadClass($name);
        $className = $payloadClass . 'ContractTest';
        $namespace = "App\\Tests\\Modules\\{$module}\\Integration";

        $imports = [
            'use PHPUnit\\Framework\\Attributes\\Test;',
            'use PHPUnit\\Framework\\TestCase;',
            "use Semitexa\\Modules\\{$module}\\Application\\Payload\\Request\\{$payloadClass};",
            'use Semitexa\\Testing\\Traits\\TestsPayloads;',
        ];
        sort($imports);

        $template = $this->templateResolver->resolve('test-payload-contract.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'className' => $className,
            'payloadClass' => $payloadClass,
            'testMethod' => $this->snake($payloadClass) . '_contract_holds',
        ], 'make:test');

        return new PlannedFile(
            "src/modules/{$module}/tests/Integration/{$className}.php",
            $content,
            FileType::PhpClass,
        );
    }

    private function handlerTest(string $module, string $name): PlannedFile
    {
        $handlerClass = $this->inflector->toHandlerClass($name);
        $payloadClass = $this->inflector->toPayloadClass($name);
        $resourceClass = $this->inflector->toResponseClass($name);
        $className = $handlerClass . 'Test';
        $namespace = "App\\Tests\\Modules\\{$module}\\Unit";

        $imports = [
            'use PHPUnit\\Framework\\Attributes\\Test;',
            'use PHPUnit\\Framework\\TestCase;',
            'use Semitexa\\Core\\Contract\\TypedHandlerInterface;',
            "use Semitexa\\Modules\\{$module}\\Application\\Handler\\PayloadHandler\\{$handlerClass};",
        ];
        sort($imports);

        $template = $this->templateResolver->resolve('test-handler.php.tpl');
        $content = $this->renderer->render($template, [
            'namespace' => $namespace,
            'imports' => implode("\n", $imports),
            'className' => $className,
            'handlerClass' => $handlerClass,
            'payloadClass' => $payloadClass,
            'resourceClass' => $resourceClass,
            'testMethod' => $this->snake($handlerClass) . '_is_a_typed_handler',
        ], 'make:test');

        return new PlannedFile(
            "src/modules/{$module}/tests/Unit/{$className}.php",
            $content,
            FileType::PhpClass,
        );
    }

    private function snake(string $studly): string
    {
        return str_replace('-', '_', $this->inflector->toKebab($studly));
    }
}
