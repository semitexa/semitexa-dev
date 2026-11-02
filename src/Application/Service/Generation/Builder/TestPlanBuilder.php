<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\ModuleNamespace;
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
     * @param array{module: string, name: string, target?: string, dryRun?: bool, freshHandler?: bool} $params
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
            $files[] = $this->handlerTest($module, $params['name'], $params['freshHandler'] ?? false);
        }

        return new GenerationPlan(
            command: 'make:test',
            files: $files,
            dryRun: $params['dryRun'] ?? false,
        );
    }

    private function payloadTest(string $module, string $name): PlannedFile
    {
        $moduleNs = ModuleNamespace::of($module);
        $payloadClass = $this->inflector->toPayloadClass($name);
        $className = $payloadClass . 'ContractTest';
        $namespace = "App\\Tests\\Modules\\{$module}\\Integration";

        $imports = [
            'use PHPUnit\\Framework\\Attributes\\Test;',
            'use PHPUnit\\Framework\\TestCase;',
            "use {$moduleNs}\\Application\\Payload\\Request\\{$payloadClass};",
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

    private function handlerTest(string $module, string $name, bool $freshHandler): PlannedFile
    {
        $moduleNs = ModuleNamespace::of($module);
        $handlerClass = $this->inflector->toHandlerClass($name);
        $payloadClass = $this->inflector->toPayloadClass($name);
        $resourceClass = $this->inflector->toResponseClass($name);
        $className = $handlerClass . 'Test';
        $namespace = "App\\Tests\\Modules\\{$module}\\Unit";

        // A handler make:page just wrote, or one that injects nothing, is run:
        // the test calls handle() and asserts its answer, where it used to
        // assert only the interface beside a commented TODO
        // (bench-make-page-placeholder-tests). A handler with #[InjectAs*]
        // properties (or one not loadable here) reads services a bare `new`
        // leaves unset, so its test asserts the contract and names what to set.
        $handlerFqcn = "{$moduleNs}\\Application\\Handler\\PayloadHandler\\{$handlerClass}";
        $injected = $freshHandler ? [] : self::injectedProperties($handlerFqcn);
        $runs = $freshHandler || $injected === [];
        $imports = [
            'use PHPUnit\\Framework\\Attributes\\Test;',
            'use PHPUnit\\Framework\\TestCase;',
            'use Semitexa\\Core\\Contract\\TypedHandlerInterface;',
            "use {$moduleNs}\\Application\\Handler\\PayloadHandler\\{$handlerClass};",
            "use {$moduleNs}\\Application\\Payload\\Request\\{$payloadClass};",
            "use {$moduleNs}\\Application\\Resource\\Response\\{$resourceClass};",
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
            'doc' => $runs
                ? " * What {$handlerClass} answers: as generated, a {$resourceClass}. When handle()\n * fills it, assert what it puts there, e.g. `\$resource->getRenderContext()['…']`."
                : " * {$handlerClass} keeps the handler contract. handle() is not called here: it\n * reads services the container injects, which a bare `new` leaves unset.",
            'body' => $runs
                ? "        \$handler = new {$handlerClass}();\n\n        self::assertInstanceOf(TypedHandlerInterface::class, \$handler);\n        self::assertInstanceOf({$resourceClass}::class, \$handler->handle(new {$payloadClass}(), new {$resourceClass}()), 'the handler answers with its resource');"
                : "        // Set " . ($injected === null ? 'the services it injects' : implode(', ', array_map(static fn (string $p): string => '$' . $p, $injected))) . " on the handler, then\n        // assert what handle(new {$payloadClass}(), new {$resourceClass}()) puts on the resource.\n        \$handler = new {$handlerClass}();\n\n        self::assertInstanceOf(TypedHandlerInterface::class, \$handler);",
            'testMethod' => $this->snake($handlerClass) . ($runs ? '_answers_with_its_resource' : '_is_a_typed_handler'),
        ], 'make:test');

        return new PlannedFile(
            "src/modules/{$module}/tests/Unit/{$className}.php",
            $content,
            FileType::PhpClass,
        );
    }

    /**
     * The handler's container-injected properties: [] when it has none,
     * null when the class cannot be loaded here (so nothing is assumed).
     *
     * @return list<string>|null
     */
    private static function injectedProperties(string $class): ?array
    {
        if (!class_exists($class)) {
            return null;
        }
        $names = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            foreach ($property->getAttributes() as $attribute) {
                if (str_starts_with($attribute->getName(), 'Semitexa\\Core\\Attribute\\InjectAs')) {
                    $names[] = $property->getName();
                    break;
                }
            }
        }

        return $names;
    }

    private function snake(string $studly): string
    {
        return str_replace('-', '_', $this->inflector->toKebab($studly));
    }
}
