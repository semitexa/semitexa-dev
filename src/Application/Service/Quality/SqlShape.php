<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

/**
 * A statement with its values taken out: what kind of query it is.
 *
 * Bindings already sit outside the SQL, so the shape is mostly the text with
 * its formatting whitespace collapsed — outside quoted tokens only, since
 * 'a  b' and 'a b' are different literals. The exception is an IN list: the
 * ORM spells one placeholder per value (`IN (:in0, :in1, :in2)`), so without
 * folding, the same query over three ids and over four would count as two
 * different kinds.
 */
final class SqlShape
{
    public static function of(string $sql): string
    {
        // Split on quoted tokens ('…', "…", `…`, with doubled-quote and
        // backslash escapes) and collapse whitespace only between them.
        $parts = preg_split("/('(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.|\"\")*\"|`(?:[^`]|``)*`)/s", trim($sql), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sql];
        $shape = '';
        foreach ($parts as $i => $part) {
            $shape .= $i % 2 === 1 ? $part : (string) preg_replace('/\s+/', ' ', $part);
        }

        return (string) preg_replace('/\bIN\s*\(\s*:in\d+(?:\s*,\s*:in\d+)*\s*\)/i', 'IN (…)', $shape);
    }

    /**
     * One execution's identity: its shape and its bindings, with named
     * bindings in a stable order (the same values bound in another order are
     * the same statement) and positional ones left as they are.
     *
     * @param array<array-key, mixed>|null $bindings
     */
    public static function execution(string $sql, ?array $bindings): string
    {
        if (is_array($bindings) && !array_is_list($bindings)) {
            ksort($bindings);
        }

        return self::of($sql) . '|' . json_encode($bindings, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
