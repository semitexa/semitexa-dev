<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * `module_structure.job_wrong_location`: a scheduled job (`#[AsScheduledJob]`
 * / `ScheduledJobInterface`) lives under `Application/Job/<Feature>/`.
 *
 * Global like `command_wrong_location`, but read from what the class declares
 * ({@see ScheduledJobDeclaration}) rather than its basename, and over the
 * whole code root: a directory the allowlist walk rejects or treats as opaque
 * hides no job.
 *
 * Two severities, because the scheduler runs a job by the class name stored
 * in its schedule row. A job that already exists elsewhere cannot simply be
 * moved, so it is a warning for one release. A job file the verified change
 * ADDS has no row yet: it is an error, and the move is free.
 */
final class ScheduledJobPlacement
{
    /** Module-relative home of scheduled jobs. */
    public const JOB_PATH = 'Application/Job';

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /**
     * @param list<string> $addedFiles project-relative paths the change being verified adds
     *
     * @return list<ModuleStructureViolation>
     */
    public function violations(DetectedModule $module, array $addedFiles = []): array
    {
        $codeRootRel = $module->relativePath . '/src';
        $codeRoot    = $this->projectRoot . '/' . $codeRootRel;
        if (!is_dir($codeRoot)) {
            return [];
        }
        $added = [];
        foreach ($addedFiles as $path) {
            $added[ltrim($path, '/')] = true;
        }

        $violations = [];
        foreach ($this->phpFilesOutsideJobPath($codeRoot) as $inside) {
            $source = @file_get_contents($codeRoot . '/' . $inside);
            if ($source === false) {
                // A file that cannot be read was not checked: say so, never pass it.
                $violations[] = new ModuleStructureViolation(
                    code: ModuleStructureViolation::CODE_JOB_WRONG_LOCATION,
                    module: $module->relativePath,
                    path: $codeRootRel . '/' . $inside,
                    message: sprintf("'%s' cannot be read, so whether it is a scheduled job outside %s/ is unknown.", basename($inside), self::JOB_PATH),
                    expected: 'a readable PHP file',
                    actual: 'unreadable (permissions, or a link to nothing)',
                    suggestedFix: 'Make the file readable or remove it, then verify again.',
                );
                continue;
            }
            if (!ScheduledJobDeclaration::in($source)) {
                continue;
            }

            $path   = $codeRootRel . '/' . $inside;
            $isNew  = isset($added[$path]);
            $file   = basename($inside);
            $name   = basename($inside, '.php');
            $target = sprintf(
                '%s/%s/<Feature>/%s.php',
                $codeRootRel,
                self::JOB_PATH,
                str_ends_with($name, 'Job') ? $name : $name . 'Job',
            );

            $violations[] = new ModuleStructureViolation(
                code: ModuleStructureViolation::CODE_JOB_WRONG_LOCATION,
                module: $module->relativePath,
                path: $path,
                message: $isNew
                    ? sprintf("New scheduled job '%s' is outside %s/. Create it at %s.", $file, self::JOB_PATH, $target)
                    : sprintf("Scheduled job '%s' is outside %s/; its home is %s. A warning in this release, an error in the next.", $file, self::JOB_PATH, $target),
                expected: $target,
                actual: $path,
                suggestedFix: $isNew
                    ? sprintf('Move the new file to %s and set its namespace to match. Nothing stores its class name yet, so the move is free now.', $target)
                    : sprintf('Move it to %s with its namespace. The scheduler runs a job by the class name stored in its schedule row, so migrate the schedule rows of an existing job (an #[AsDataPatch]) in the same change.', $target),
                severity: $isNew ? ModuleStructureViolation::SEVERITY_ERROR : ModuleStructureViolation::SEVERITY_WARNING,
            );
        }

        return $violations;
    }

    /**
     * @return list<string> paths inside the code root, sorted
     */
    private function phpFilesOutsideJobPath(string $codeRoot): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($codeRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            // Not isFile(): a link to nothing is a candidate too, and is reported as unreadable.
            if (!$file instanceof \SplFileInfo || $file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $inside = substr($file->getPathname(), strlen($codeRoot) + 1);
            if (!str_starts_with($inside, self::JOB_PATH . '/')) {
                $files[] = $inside;
            }
        }
        sort($files);

        return $files;
    }
}
