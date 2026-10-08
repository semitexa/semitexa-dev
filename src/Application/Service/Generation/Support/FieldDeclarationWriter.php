<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Semitexa\PlatformUi\Domain\Model\Field\Field;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * A field declaration as the PHP a developer would write for it —
 * `Field::text('title')->required()->set('max', 160)->searchable()` — for the
 * generators (make:crud, make:form). Only what differs from the constructor's
 * own defaults is written, so the output reads like hand-written code.
 */
final class FieldDeclarationWriter
{
    /** @var array<string, string>|null type → Field constructor */
    private static ?array $constructors = null;

    public function write(UiField $field): string
    {
        $constructor = self::constructors()[$field->type] ?? null;
        $plain = $constructor === null ? Field::of($field->name, $field->type) : Field::{$constructor}($field->name);

        $code = match (true) {
            $constructor === null => sprintf("Field::of(%s, %s)", self::literal($field->name), self::literal($field->type)),
            $constructor === 'id' && $field->name === 'id' => 'Field::id()',
            $constructor === 'decimal' && $field->setting('scale', 2) !== 2 => sprintf('Field::decimal(%s, %d)', self::literal($field->name), (int) $field->setting('scale')),
            default => sprintf('Field::%s(%s)', $constructor, self::literal($field->name)),
        };
        if ($field->label !== $plain->label) {
            $code .= '->label(' . self::literal($field->label) . ')';
        }
        if ($field->required && !$plain->required) {
            $code .= '->required()';
        }
        if ($field->readOnly && !$plain->readOnly) {
            $code .= '->readOnly()';
        }
        foreach (['max', 'min'] as $setting) {
            if ($field->setting($setting) !== null && $field->setting($setting) !== $plain->setting($setting)) {
                $code .= sprintf("->set('%s', %s)", $setting, var_export($field->setting($setting), true));
            }
        }
        if (!$field->onList && $plain->onList) {
            $code .= '->hideOnList()';
        }
        foreach (['searchable', 'sortable', 'filterable'] as $flag) {
            if ($field->{$flag} && !$plain->{$flag}) {
                $code .= '->' . $flag . '()';
            }
        }

        return $code;
    }

    /** @return array<string, string> */
    private static function constructors(): array
    {
        if (self::$constructors === null) {
            self::$constructors = [];
            foreach ((new \ReflectionClass(Field::class))->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
                if ($method->name !== 'of') {
                    self::$constructors[$method->invoke(null, 'x')->type] = $method->name;
                }
            }
        }

        return self::$constructors;
    }

    private static function literal(string $value): string
    {
        return var_export($value, true);
    }
}
