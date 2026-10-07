<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\FieldDeclarationWriter;
use Semitexa\Dev\Application\Service\Generation\Support\ModelFieldInference;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Orm\Metadata\ResourceModelMetadata;

/**
 * make:crud — one #[AsCrud] class over an ORM model, its fields inferred from
 * the model's columns, and a unit test that the screen boots and every field
 * maps to a column.
 */
final class CrudPlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param array{module: string, name: string, metadata: ResourceModelMetadata, path?: ?string, permission?: ?string, nav?: ?string, layout?: ?string, noTest?: bool, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $name = $this->inflector->toStudly($params['name']);
        $metadata = $params['metadata'];
        $className = $name . 'Crud';
        $modelClass = $metadata->className;
        $modelShort = substr($modelClass, (int) strrpos($modelClass, '\\') + 1);

        $label = ucfirst(strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name))));
        // The same plural the screen will use (AsCrud::plural()).
        $plural = \Semitexa\Crud\Attribute\AsCrud::pluralOf($label);
        $pluralKebab = $this->inflector->toKebab(\Semitexa\Crud\Attribute\AsCrud::pluralOf($name));
        $moduleKebab = $this->inflector->toKebab($module);
        $id = $moduleKebab . '.' . $pluralKebab;
        $path = $params['path'] ?? ('/' . $moduleKebab . '/' . $pluralKebab);
        $permission = $params['permission'] ?? $pluralKebab;
        $nav = $params['nav'] ?? $module;

        $args = [
            'id' => $id,
            'path' => $path,
            'model' => $modelShort . '::class',
            'label' => $label,
            'permission' => $permission,
            'nav' => $nav,
        ];
        if (($params['layout'] ?? null) !== null) {
            $args['layout'] = $params['layout'];
        }
        $attributeArgs = [];
        foreach ($args as $key => $value) {
            $attributeArgs[] = '    ' . $key . ': ' . ($key === 'model' ? $value : var_export($value, true)) . ',';
        }

        $writer = new FieldDeclarationWriter();
        $fields = array_map(
            static fn ($field): string => '            ' . $writer->write($field) . ',',
            (new ModelFieldInference())->fields($metadata),
        );

        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Payload\\Request\\Crud";
        $files = [
            new PlannedFile(
                "src/modules/{$module}/src/Application/Payload/Request/Crud/{$className}.php",
                $this->renderer->render($this->templateResolver->resolve('crud.php.tpl'), [
                    'namespace' => $namespace,
                    'modelClass' => ltrim($modelClass, '\\'),
                    'modelShort' => $modelShort,
                    'className' => $className,
                    'plural' => $plural,
                    'permissionNote' => "{$permission}.read / .create / .edit / .delete",
                    'attributeArgs' => implode("\n", $attributeArgs),
                    'fields' => implode("\n", $fields),
                ], 'make:crud'),
                FileType::PhpClass,
            ),
        ];
        if (!($params['noTest'] ?? false)) {
            $files[] = new PlannedFile(
                "src/modules/{$module}/tests/Unit/{$className}Test.php",
                $this->renderer->render($this->templateResolver->resolve('crud-test.php.tpl'), [
                    'namespace' => "App\\Tests\\Modules\\{$module}\\Unit",
                    'crudClass' => $namespace . '\\' . $className,
                    'crudShort' => $className,
                    'modelClass' => ltrim($modelClass, '\\'),
                    'modelShort' => $modelShort,
                    'className' => $className . 'Test',
                    'plural' => $plural,
                    'screenId' => $id,
                ], 'make:crud'),
                FileType::PhpClass,
            );
        }

        return new GenerationPlan(command: 'make:crud', files: $files, dryRun: $params['dryRun']);
    }
}
