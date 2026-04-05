<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Builder;

use Semitexa\Dev\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\GenerationPlan;
use Semitexa\Dev\Generation\Data\PlannedFile;

final class ModulePlanBuilder
{
    public function __construct(
        private readonly NameInflectorInterface $inflector,
    ) {}

    /**
     * @param array{name: string, dryRun: bool} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['name']);
        $base = "src/modules/{$module}";

        $gitkeep = "# This directory is part of the {$module} module.\n";

        $files = [
            new PlannedFile("{$base}/Application/Payload/Request/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Application/Handler/PayloadHandler/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Application/Resource/Response/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Application/Handler/DomainListener/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Application/Command/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Application/View/templates/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Domain/Service/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Domain/Contract/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Domain/Event/.gitkeep", $gitkeep, FileType::PhpClass),
            new PlannedFile("{$base}/Domain/Model/.gitkeep", $gitkeep, FileType::PhpClass),
        ];

        return new GenerationPlan(
            command: 'make:module',
            files: $files,
            dryRun: $params['dryRun'] ?? false,
        );
    }
}
