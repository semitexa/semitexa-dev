<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Support;

/**
 * Reads a decoded JSON document in a test: says what a value is before the
 * test looks inside it, so a document of another shape fails here, with the
 * shape in the message, and not as a warning about an offset of null.
 */
final class JsonRead
{
    /** @return array<array-key, mixed> */
    public static function map(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Expected an object or a list, got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    /** @return list<array<array-key, mixed>> */
    public static function rows(mixed $value): array
    {
        $rows = [];
        foreach (self::map($value) as $row) {
            $rows[] = self::map($row);
        }

        return $rows;
    }

    public static function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Expected a string, got ' . get_debug_type($value) . '.');
        }

        return $value;
    }
}
