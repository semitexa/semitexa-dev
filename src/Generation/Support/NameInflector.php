<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Support;

use Semitexa\Core\Support\Str;
use Semitexa\Dev\Generation\Contract\NameInflectorInterface;

final class NameInflector implements NameInflectorInterface
{
    public function toStudly(string $input): string
    {
        return Str::toStudly($input);
    }

    public function toKebab(string $input): string
    {
        // Convert StudlyCase or snake_case to kebab-case
        $result = preg_replace('/([a-z])([A-Z])/', '$1-$2', $input);
        $result = preg_replace('/[_\s]+/', '-', $result);
        return strtolower($result);
    }

    public function toPayloadClass(string $input): string
    {
        return $this->withSuffix($input, 'Payload');
    }

    public function toHandlerClass(string $input): string
    {
        return $this->withSuffix($input, 'Handler');
    }

    public function toResponseClass(string $input): string
    {
        return $this->withSuffix($input, 'Response');
    }

    private function withSuffix(string $input, string $suffix): string
    {
        // If input already ends with the suffix (case-sensitive), return as-is after studly
        if (str_ends_with($input, $suffix)) {
            return $input;
        }

        return $this->toStudly($input) . $suffix;
    }

    public function toTemplateName(string $input): string
    {
        return $this->toKebab($input) . '.html.twig';
    }
}
