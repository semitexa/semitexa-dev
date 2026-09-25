<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Explorer;

/**
 * What the Explorer needs to prefill a field, and the OPTIONS contract does
 * not carry: the cases of an enum parameter, a declared default, and a format
 * guessed from the field's name.
 *
 * Read from the payload's setters, the same surface the hydrator writes
 * through, and the property of the same name for defaults. Validation rules
 * stay invisible — they are imperative calls inside the setters — so a preset
 * built from these hints is a good first guess, never a guarantee.
 */
final class FieldHintReflector
{
    /** Name fragment → format, first match wins. */
    private const FORMATS = [
        'email' => 'email',
        'url' => 'url',
        'uri' => 'url',
        'uuid' => 'uuid',
        'date' => 'date',
        'time' => 'datetime',
        'at' => 'datetime',
        'phone' => 'phone',
        'slug' => 'slug',
        'password' => 'password',
        'currency' => 'currency',
        'locale' => 'locale',
        'color' => 'color',
    ];

    /**
     * @param class-string $payloadClass
     * @return array<string, array{enum?: list<string|int>, default?: mixed, format?: string}>
     */
    public static function hints(string $payloadClass): array
    {
        if (!class_exists($payloadClass)) {
            return [];
        }

        $class = new \ReflectionClass($payloadClass);
        $defaults = $class->getDefaultProperties();
        $hints = [];

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfParameters() !== 1 || !str_starts_with($method->getName(), 'set')) {
                continue;
            }
            $field = lcfirst(substr($method->getName(), 3));
            if ($field === '') {
                continue;
            }

            $hint = [];
            $param = $method->getParameters()[0];
            $enum = self::enumCases($param->getType());
            if ($enum !== []) {
                $hint['enum'] = $enum;
            }
            if ($param->isDefaultValueAvailable()) {
                $hint['default'] = self::scalar($param->getDefaultValue());
            } elseif (array_key_exists($field, $defaults) && $defaults[$field] !== null) {
                $hint['default'] = self::scalar($defaults[$field]);
            }
            // `''` and `[]` are "unset" placeholders, not values anyone chose —
            // a sample value says more than an empty default.
            if (array_key_exists('default', $hint) && in_array($hint['default'], [null, '', []], true)) {
                unset($hint['default']);
            }
            $format = self::format($field);
            if ($format !== null) {
                $hint['format'] = $format;
            }

            if ($hint !== []) {
                $hints[$field] = $hint;
            }
        }

        return $hints;
    }

    public static function format(string $field): ?string
    {
        $words = preg_split('/(?=[A-Z])|_/', $field) ?: [];
        $words = array_map('strtolower', array_filter($words, static fn (string $w): bool => $w !== ''));
        foreach (self::FORMATS as $needle => $format) {
            if (in_array($needle, $words, true)) {
                return $format;
            }
        }

        return null;
    }

    /** @return list<string|int> */
    private static function enumCases(?\ReflectionType $type): array
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !enum_exists($type->getName())) {
            return [];
        }
        $enum = $type->getName();
        if (!is_subclass_of($enum, \BackedEnum::class)) {
            return array_map(static fn (\UnitEnum $c): string => $c->name, $enum::cases());
        }

        return array_map(static fn (\BackedEnum $c): string|int => $c->value, $enum::cases());
    }

    private static function scalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return is_scalar($value) || is_array($value) ? $value : null;
    }
}
