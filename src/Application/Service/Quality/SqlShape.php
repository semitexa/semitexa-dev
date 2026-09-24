<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

/**
 * A statement with its values taken out: what kind of query it is.
 *
 * Bindings already sit outside the SQL, so the shape is mostly the text with
 * its whitespace collapsed. The exception is an IN list: the ORM spells one
 * placeholder per value (`IN (:in0, :in1, :in2)`), so without folding, the same
 * query over three ids and over four would count as two different kinds.
 */
final class SqlShape
{
    public static function of(string $sql): string
    {
        $shape = (string) preg_replace('/\s+/', ' ', trim($sql));

        return (string) preg_replace('/IN \(\s*:in\d+(?:\s*,\s*:in\d+)*\s*\)/i', 'IN (…)', $shape);
    }
}
