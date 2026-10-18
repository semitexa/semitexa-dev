<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * One target per changed Playwright spec.
 *
 * Before this a changed `tests/E2E/*.spec.ts` was read by no check: verify runs
 * inside the app container, which has neither a browser nor Docker, so the run
 * came back "no check read" for every spec it was given. The browser is on the
 * host. `bin/semitexa ai:verify` asks for this list first (`--list-e2e`), runs
 * each spec there in the e2e-runner — only against an app that is already
 * running; verify never starts containers — and files the outcome where
 * {@see E2eSpecResultCheck} reads it.
 *
 * Not at minimal scope: a browser run is the most expensive check there is,
 * which is the tier the minimal contract leaves out.
 */
final class E2eSpecTargets
{
    /**
     * @param list<ChangedFile> $changedFiles
     *
     * @return list<VerificationTarget>
     */
    public static function of(array $changedFiles, string $effectiveScope): array
    {
        if ($effectiveScope === VerificationPlan::SCOPE_MINIMAL) {
            return [];
        }

        $targets = [];
        foreach ($changedFiles as $file) {
            if ($file->status === ChangedFile::STATUS_DELETED || !self::isSpec($file->path)) {
                continue;
            }
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_E2E,
                id: "e2e:{$file->path}",
                reason: 'changed Playwright spec — run in the e2e-runner on the host, against the running app',
                triggeredBy: [$file->path],
                filePath: $file->path,
            );
        }

        return $targets;
    }

    /** A spec the E2E phase discovers: a *.spec.ts under a tests/E2E dir of a package or a local module. */
    public static function isSpec(string $path): bool
    {
        return preg_match('#^(packages/[^/]+|src/modules/[^/]+)/tests/E2E/(?:[^/]+/)*[^/]+\.spec\.ts$#', $path) === 1;
    }
}
