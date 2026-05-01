<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Builder;

use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;

final class ModulePlanBuilder
{
    public const TARGET_CUSTOM = 'custom';
    public const TARGET_PACKAGE = 'package';

    public function __construct(
        private readonly NameInflectorInterface $inflector,
    ) {}

    /**
     * @param array{name: string, dryRun?: bool, target?: string} $params
     */
    public function build(array $params): GenerationPlan
    {
        $module = $this->inflector->toStudly($params['name']);
        $target = $this->normalizeTarget($params['target'] ?? self::TARGET_CUSTOM);
        $base = $target === self::TARGET_PACKAGE
            ? sprintf('packages/semitexa-%s/src', $this->inflector->toKebab($module))
            : "src/modules/{$module}";

        $gitkeep = "# This directory is part of the {$module} module.\n";

        $files = [];

        if ($target === self::TARGET_PACKAGE) {
            $files[] = new PlannedFile(
                sprintf('packages/semitexa-%s/composer.json', $this->inflector->toKebab($module)),
                $this->buildPackageComposerManifest($module),
                FileType::JsonFile,
            );
        }

        $files = array_merge($files, [
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
        ]);

        return new GenerationPlan(
            command: 'make:module',
            files: $files,
            dryRun: $params['dryRun'] ?? false,
        );
    }

    private function normalizeTarget(string $target): string
    {
        return match ($target) {
            self::TARGET_CUSTOM, self::TARGET_PACKAGE => $target,
            default => self::TARGET_CUSTOM,
        };
    }

    private function buildPackageComposerManifest(string $module): string
    {
        $packageName = sprintf('semitexa/%s', $this->inflector->toKebab($module));

        $manifest = [
            'name' => $packageName,
            'description' => sprintf('%s Semitexa module', $module),
            'type' => 'semitexa-module',
            'license' => 'MIT',
            'require' => [
                'php' => '>=8.4',
                'semitexa/core' => '*',
            ],
            'autoload' => [
                'psr-4' => [
                    sprintf('Semitexa\\%s\\', $module) => 'src/',
                ],
            ],
            'extra' => [
                'semitexa-module' => [
                    'extends' => null,
                    'name' => $module,
                    'description' => sprintf('%s Semitexa module', $module),
                ],
            ],
        ];

        return (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
