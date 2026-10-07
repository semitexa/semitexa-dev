<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Contract\TemplateResolverInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\FieldDeclarationWriter;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/**
 * make:form — a platform.form without CRUD: a submit action that declares its
 * fields (UiFormFieldsInterface), the partial that renders them, and a test.
 */
final class FormPlanBuilder
{
    /** Types a command line can declare; a choice or a relation needs options it cannot carry. */
    public const TYPES = ['text', 'textarea', 'slug', 'email', 'url', 'integer', 'decimal', 'boolean', 'date', 'datetime'];

    /** A value each type accepts, for the generated test. */
    private const SAMPLES = [
        'text' => 'Text', 'textarea' => 'Some text', 'slug' => 'a-slug', 'email' => 'someone@example.test',
        'url' => 'https://example.test', 'integer' => '1', 'decimal' => '1.00', 'boolean' => '1',
        'date' => '2026-01-01', 'datetime' => '2026-01-01T10:00',
    ];

    public function __construct(
        private readonly NameInflectorInterface $inflector,
        private readonly TemplateResolverInterface $templateResolver,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * "name:text!,email:email!,message:textarea" — `!` marks a required field.
     *
     * @return list<array{name: string, type: string, required: bool}>
     */
    public static function parseFields(string $spec): array
    {
        $fields = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $part) {
            if (preg_match('/\A([a-z][A-Za-z0-9]*):([a-z]+)(!?)\z/', $part, $m) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not name:type (or name:type! for a required field).', $part));
            }
            if (!in_array($m[2], self::TYPES, true)) {
                throw new \InvalidArgumentException(sprintf('Field "%s": type "%s" is not one of %s.', $m[1], $m[2], implode(', ', self::TYPES)));
            }
            if (in_array($m[1], array_column($fields, 'name'), true)) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is declared twice.', $m[1]));
            }
            $fields[] = ['name' => $m[1], 'type' => $m[2], 'required' => $m[3] === '!'];
        }
        if ($fields === []) {
            throw new \InvalidArgumentException('A form needs at least one field.');
        }

        return $fields;
    }

    /**
     * @param array{module: string, name: string, fields: list<array{name: string, type: string, required: bool}>, submitText?: ?string, noTest?: bool, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['module']);
        $name = $this->inflector->toStudly($params['name']);
        $kebab = $this->inflector->toKebab($name);
        $className = $name . 'FormAction';
        $actionName = $this->inflector->toKebab($module) . '.' . $kebab;
        $title = ucfirst(strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name))));
        $namespace = "Semitexa\\Modules\\{$module}\\Application\\Service\\Submit";

        $writer = new FieldDeclarationWriter();
        $declarations = [];
        $sample = [];
        foreach ($params['fields'] as $spec) {
            $field = Field::{$spec['type']}($spec['name']);
            $declarations[] = '            ' . $writer->write($spec['required'] ? $field->required() : $field) . ',';
            $sample[$spec['name']] = self::SAMPLES[$spec['type']];
        }
        $sampleCode = '[' . implode(', ', array_map(static fn (string $k, string $v): string => var_export($k, true) . ' => ' . var_export($v, true), array_keys($sample), $sample)) . ']';
        $vars = [
            'namespace' => $namespace,
            'className' => $className,
            'actionName' => $actionName,
            'title' => $title,
            'module' => $module,
            'kebab' => $kebab,
            'fields' => implode("\n", $declarations),
            'submitText' => $params['submitText'] ?? 'Send',
            'doneMessage' => 'Sent.',
        ];

        $files = [
            new PlannedFile("src/modules/{$module}/src/Application/Service/Submit/{$className}.php",
                $this->renderer->render($this->templateResolver->resolve('form-action.php.tpl'), $vars, 'make:form'), FileType::PhpClass),
            new PlannedFile("src/modules/{$module}/src/Application/View/templates/partials/{$kebab}-form.html.twig",
                $this->renderer->render($this->templateResolver->resolve('form-partial.html.twig.tpl'), $vars), FileType::TwigTemplate),
        ];
        if (!($params['noTest'] ?? false)) {
            $files[] = new PlannedFile("src/modules/{$module}/tests/Unit/{$className}Test.php",
                $this->renderer->render($this->templateResolver->resolve('form-test.php.tpl'), [
                    'namespace' => "App\\Tests\\Modules\\{$module}\\Unit",
                    'actionClass' => $namespace . '\\' . $className,
                    'actionShort' => $className,
                    'className' => $className . 'Test',
                    'title' => $title,
                    'sample' => $sampleCode,
                    'fieldNames' => '[' . implode(', ', array_map(static fn (string $k): string => var_export($k, true), array_keys($sample))) . ']',
                ], 'make:form'), FileType::PhpClass);
        }

        return new GenerationPlan(command: 'make:form', files: $files, dryRun: $params['dryRun']);
    }
}
