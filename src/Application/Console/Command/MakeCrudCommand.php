<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Generation\Builder\CrudPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationExitCode;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationOutcomeRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationPreflight;
use Semitexa\Dev\Application\Service\Generation\Support\JsonResultFormatter;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\ReplayArgBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;
use Semitexa\Dev\Application\Service\Generation\Verifier\PostWriteLinter;
use Semitexa\Dev\Application\Service\Generation\Writer\SafeFileWriter;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * make:crud — a whole CRUD screen as one #[AsCrud] class, its fields read off
 * an ORM model, plus a test that it boots.
 */
#[AsCommand(name: 'make:crud', description: 'Scaffold a CRUD screen (#[AsCrud]) over an ORM model: fields inferred from its columns, plus a boot test')]
final class MakeCrudCommand extends BaseCommand
{
    /** {@see MakeServiceCommand::REQUIRED_OPTIONS} */
    public const REQUIRED_OPTIONS = ['module', 'model'];

    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'The ORM resource model: its class, or its short name inside the module')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Screen name (default: the model name without its module prefix and "Resource")')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Route path (default: /{module}/{records})')
            ->addOption('permission', null, InputOption::VALUE_REQUIRED, 'Permission prefix (default: the records, e.g. "products" → products.read …)')
            ->addOption('nav', null, InputOption::VALUE_REQUIRED, 'Navigation group (default: the module name)')
            ->addOption('no-test', null, InputOption::VALUE_NONE, 'Skip the boot test')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing (explicit)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually create files (dry-run is the default)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rejected = GenerationPreflight::check($input, $output, 'make:crud', self::REQUIRED_OPTIONS, $this->getProjectRoot());
        if ($rejected !== null) {
            return $rejected;
        }

        $inflector = new NameInflector();
        $module = $inflector->toStudly((string) $input->getOption('module'));
        $modelClass = $this->resolveModel((string) $input->getOption('model'), $module);
        if ($modelClass === null) {
            $io->error(sprintf('No ORM model "%s": give its class, or the short name of a model under src/modules/%s/src/Application/Db/.', $input->getOption('model'), $module));

            return self::FAILURE;
        }
        $modelShort = substr($modelClass, (int) strrpos($modelClass, '\\') + 1);
        $name = (string) ($input->getOption('name') ?? self::screenName($modelShort, $module));
        $layoutFile = "src/modules/{$module}/src/Application/View/templates/layouts/app.html.twig";
        $hasLayout = is_file($this->getProjectRoot() . '/' . $layoutFile);

        $plan = (new CrudPlanBuilder($inflector, new TemplateResolver(), new TemplateRenderer()))->build([
            'module' => $module,
            'name' => $name,
            'metadata' => ResourceModelMetadataRegistry::default()->for($modelClass),
            'path' => $input->getOption('path'),
            'permission' => $input->getOption('permission'),
            'nav' => $input->getOption('nav'),
            'layout' => $hasLayout ? "@project-layouts-{$module}/layouts/app.html.twig" : null,
            'noTest' => (bool) $input->getOption('no-test'),
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);
        $replayArgs = ReplayArgBuilder::fromInput($input, ['module', 'model', 'name', 'path', 'permission', 'nav']);
        $permission = (string) ($input->getOption('permission') ?? $inflector->toKebab(\Semitexa\Crud\Attribute\AsCrud::pluralOf($inflector->toStudly($name))));
        $steps = [
            sprintf('Grant %1$s.read, %1$s.create, %1$s.edit and %1$s.delete to the roles that manage these records', $permission),
            $hasLayout && !str_contains((string) file_get_contents($this->getProjectRoot() . '/' . $layoutFile), 'crud_nav(')
                ? "List the screen in {$layoutFile}: {% set appNav = crud_nav([...]) %}"
                : null,
            'Edit the fields: options for choices, labels, help; then bin/semitexa server:restart and open the screen',
            'bin/semitexa ai:verify --dirty',
        ];
        $steps = array_values(array_filter($steps));

        if ($plan->dryRun) {
            $planned = new GenerationResult('make:crud', 'dry_run', array_map(static fn ($f): string => $f->path, $plan->files), next_steps: ['Re-run with --write to create files', ...$steps], replay_args: $replayArgs);
            if ($input->getOption('json')) {
                $output->writeln((new JsonResultFormatter())->format($planned));

                return self::SUCCESS;
            }
            $io->title('Dry Run — Planned Files');
            foreach ($plan->files as $file) {
                $io->section($file->path);
                $output->writeln($file->content);
            }

            return self::SUCCESS;
        }

        $result = (new SafeFileWriter($this->getProjectRoot(), 'make:crud'))->write($plan->files, (bool) $input->getOption('force'));
        $result = (new PostWriteLinter($this->getApplication()))->lintAfterWrite($result)->withReplayArgs($replayArgs)->withNextSteps($steps);
        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));

            return GenerationExitCode::forResult($result);
        }
        if ($result->created) {
            $io->success('Created: ' . implode(', ', $result->created));
            $io->listing($result->next_steps);
        }
        GenerationOutcomeRenderer::renderProblems($io, $result);

        return GenerationExitCode::forResult($result);
    }

    /** A model class, or a short name looked up under the module's Db/ directory. */
    private function resolveModel(string $model, string $module): ?string
    {
        if (str_contains($model, '\\')) {
            return class_exists($model) ? ltrim($model, '\\') : null;
        }
        $dir = $this->getProjectRoot() . "/src/modules/{$module}/src/Application/Db";
        $iterator = is_dir($dir) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) : [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getFilename() === $model . '.php'
                && preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file->getPathname()), $m) === 1) {
                $class = $m[1] . '\\' . $model;

                return class_exists($class) ? $class : null;
            }
        }

        return null;
    }

    /** PlaygroundProductResource in module Playground → Product. */
    private static function screenName(string $modelShort, string $module): string
    {
        $name = (string) preg_replace('/Resource$/', '', $modelShort);
        if (str_starts_with($name, $module) && strlen($name) > strlen($module)) {
            $name = substr($name, strlen($module));
        }

        return $name;
    }
}
