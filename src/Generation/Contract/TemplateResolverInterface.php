<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Contract;

interface TemplateResolverInterface
{
    public function resolve(string $templateName): string;
}
