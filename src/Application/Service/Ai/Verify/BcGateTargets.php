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
            // A rename is judged at both ends: the old path may have carried the
            // @api the new one dropped, and may belong to another package (review, dev#265).
            $paths = [$file->path];
            if ($file->status === ChangedFile::STATUS_RENAMED && $file->originalPath !== null) {
                $paths[] = $file->originalPath;
            }
            foreach ($paths as $index => $path) {
                if (preg_match('#^packages/([^/]+)/src/.+\.php$#', $path, $m) !== 1) {
                    continue;
                }
                $readNow = $index === 0 && $file->status !== ChangedFile::STATUS_DELETED;
                if (self::declaresApi($projectRoot, $m[1], $path, $readNow) && !in_array($file->path, $byPackage[$m[1]] ?? [], true)) {
                    $byPackage[$m[1]][] = $file->path;
                }
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
     * the break the gate exists for. A HEAD that cannot be read is not read as
     * "no @api": the gate runs, and says itself what it could not check
     * (review, dev#265).
     */
    private static function declaresApi(string $projectRoot, string $package, string $path, bool $readNow): bool
    {
        $now = $readNow ? @file_get_contents($projectRoot . '/' . $path) : false;
        if (is_string($now) && str_contains($now, '@api')) {
            return true;
        }
        $atHead = self::atHead($projectRoot . '/packages/' . $package, substr($path, strlen("packages/{$package}/")));

        return $atHead === null || str_contains($atHead, '@api');
    }

    /**
     * The file as the package repository's HEAD has it; '' when HEAD has no
     * such file; null when git could not answer.
     */
    private static function atHead(string $packageDir, string $relative): ?string
    {
        $process = @proc_open(['git', '-C', $packageDir, 'show', 'HEAD:' . $relative], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LC_ALL' => 'C'] + getenv());
        if (!is_resource($process)) {
            return null;
        }
        $content = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) === 0) {
            return $content;
        }

        // git's words for a path HEAD does not have (a new file): "does not exist
        // in 'HEAD'", or "exists on disk, but not in 'HEAD'".
        return str_contains($error, "in 'HEAD'") ? '' : null;
    }
}
