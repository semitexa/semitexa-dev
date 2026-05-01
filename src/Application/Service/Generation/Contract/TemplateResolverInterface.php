<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Contract;

interface TemplateResolverInterface
{
    public function resolve(string $templateName): string;
}
