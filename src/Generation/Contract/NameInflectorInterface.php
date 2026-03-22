<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Contract;

interface NameInflectorInterface
{
    public function toStudly(string $input): string;

    public function toKebab(string $input): string;

    public function toPayloadClass(string $input): string;

    public function toHandlerClass(string $input): string;

    public function toResponseClass(string $input): string;

    public function toTemplateName(string $input): string;
}
