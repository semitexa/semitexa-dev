<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The stability contract's BC gate, at the edit instead of at the cut.
 *
 * The release runs release-bc-gate.php over every package and refuses a break
 * on what a package marked `@api` at its previous tag. Found there, a break
 * costs a release attempt and a fix in a repository whose PR has long merged;
 * found here it costs a rerun. So a change to a PHP file under a package's src/
 * that declares `@api` now, or did at HEAD, schedules one gate run for that
 * package ({@see BcGateCheck}), against the working tree (tk-stab-bc-check-gate).
 *
 * Only in the framework workspace: the gate script and the package
 * repositories live there, and a consumer's @api is not this contract.
 */
final class BcGateTargets
{
    public const GATE_SCRIPT = 'packages/semitexa-dev/resources/skills/release-readiness/scripts/release-bc-gate.php';

    /**
     * @param list<ChangedFile> $changedFiles
     *
     * @return list<VerificationTarget>
     */
    public static function of(array $changedFiles, string $effectiveScope, string $projectRoot): array
    {
        if ($effectiveScope === VerificationPlan::SCOPE_MINIMAL || !is_file($projectRoot . '/' . self::GATE_SCRIPT)) {
            return [];
        }

        /** @var array<string, list<string>> $byPackage */
        $byPackage = [];
        foreach ($changedFiles as $file) {
            if (preg_match('#^packages/([^/]+)/src/.+\.php$#', $file->path, $m) !== 1) {
                continue;
            }
            if (self::declaresApi($projectRoot, $m[1], $file)) {
                $byPackage[$m[1]][] = $file->path;
            }
        }

        $targets = [];
        foreach ($byPackage as $package => $paths) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_BC_GATE,
                id: "bc_gate:{$package}",
                reason: 'a changed file declares @api, which the release refuses to break unless it was deprecated 90 days before; checked against the package\'s last tag now instead of at the cut',
                triggeredBy: $paths,
                filePath: "packages/{$package}",
            );
        }

        return $targets;
    }

    /**
     * Now or at HEAD: a file whose @api was removed, or that was deleted, is
     * the break the gate exists for.
     */
    private static function declaresApi(string $projectRoot, string $package, ChangedFile $file): bool
    {
        $now = $file->status === ChangedFile::STATUS_DELETED ? false : @file_get_contents($projectRoot . '/' . $file->path);
        if (is_string($now) && str_contains($now, '@api')) {
            return true;
        }

        return str_contains(self::atHead($projectRoot . '/packages/' . $package, substr($file->path, strlen("packages/{$package}/"))), '@api');
    }

    /** The file as the package repository's HEAD has it; '' when it has none. */
    private static function atHead(string $packageDir, string $relative): string
    {
        $process = @proc_open(['git', '-C', $packageDir, 'show', 'HEAD:' . $relative], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return '';
        }
        $content = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $content : '';
    }
}
