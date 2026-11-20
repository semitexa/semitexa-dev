<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpec;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ScheduledJobPlacement;

/**
 * One module against the structure spec, as a `module_structure` result.
 *
 * The allowlist walk ({@see ModuleStructureValidator}) and the scheduled-job
 * placement ({@see ScheduledJobPlacement}), which is told what the verified
 * change adds: a rule that only warns about files that already exist fails on
 * a new one. An error fails the run and rides in the diagnostics; a warning
 * never does and rides in the result's warnings, beside them and not among
 * them.
 */
final class ModuleStructureCheck
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function run(VerificationTarget $target, DetectedModule $module, ModuleStructureSpec $spec): VerificationResult
    {
        $added = $target->commandInput[VerificationTarget::INPUT_ADDED_FILES] ?? [];
        $violations = [
            ...(new ModuleStructureValidator($this->projectRoot, $spec))->validate($module),
            ...(new ScheduledJobPlacement($this->projectRoot))->violations(
                $module,
                is_array($added) ? array_values(array_filter($added, 'is_string')) : [],
            ),
        ];

        $errors   = array_values(array_filter($violations, static fn (ModuleStructureViolation $v): bool => $v->isError()));
        $warnings = array_values(array_filter($violations, static fn (ModuleStructureViolation $v): bool => !$v->isError()));
        $toArray  = static fn (ModuleStructureViolation $v): array => $v->toArray();

        $rel        = $module->relativePath;
        $errorCount = count($errors);
        if ($errorCount === 0) {
            $signal = "module_structure {$rel} → 0 violations";
            if ($warnings !== []) {
                $signal .= sprintf(', %d warning(s); first: %s %s', count($warnings), $warnings[0]->code, $warnings[0]->path);
            }
        } else {
            $first = $errors[0];
            $signal = "module_structure {$rel} → {$errorCount} error(s); first: {$first->code} {$first->path}" . $first->whyClause();
        }

        return new VerificationResult(
            target:      $target,
            status:      $errorCount === 0 ? VerificationResult::STATUS_PASS : VerificationResult::STATUS_FAIL,
            exitCode:    $errorCount === 0 ? 0 : 1,
            signal:      $signal,
            diagnostics: array_map($toArray, $errors),
            warnings:    array_map($toArray, $warnings),
        );
    }
}
