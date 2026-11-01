<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Charter;

/**
 * Whether an answer says nothing. trim() leaves a non-breaking space or an
 * em space in place, so an answer of only those would mark a question
 * answered with nothing in it; every Unicode space and separator counts here.
 */
final class Blank
{
    public static function is(string $text): bool
    {
        return preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', '', $text) === '';
    }
}
