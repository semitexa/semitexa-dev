<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

enum SampleMode: string
{
    /** The everyday one. */
    case Plain = 'plain';
    case Strict = 'strict';

    public function label(): string
    {
        return $this->value;
    }
}
