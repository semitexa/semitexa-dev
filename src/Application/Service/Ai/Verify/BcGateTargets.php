<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The stability contract's BC gate, at the edit instead of at the cut.
 *
 * The release runs release-bc-gate.php over every package and refuses a break
 * on what a package marked `@api` at its previous tag. Found there, a break
 * costs a release attempt and a fix in a repository whose PR has long merged;
 * found here it costs a rerun. So any change to a package's src/ PHP or its
 * composer.json (autoload) schedules one gate run for that package
 * ({@see BcGateCheck}), against the working tree (tk-stab-bc-check-gate), when
 * the package had `@api` at its last tag — the surface the gate judges.
 *
 * Any src file, not only one that says `@api`: an @api class breaks through a
 * parent, a trait or an interface that carries no marker (independent review,
 * dev#265). A package whose tags git cannot read is scheduled too: the gate
 * then says itself what it could not check.
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
            // A rename is judged at both ends: the old path may be in another package.
            foreach (array_filter([$file->path, $file->status === ChangedFile::STATUS_RENAMED ? $file->originalPath : null]) as $path) {
                if (preg_match('#^packages/([^/]+)/(?:src/.+\.php|composer\.json)$#', $path, $m) === 1
                    && !in_array($file->path, $byPackage[$m[1]] ?? [], true)) {
                    $byPackage[$m[1]][] = $file->path;
                }
            }
        }

        $targets = [];
        foreach ($byPackage as $package => $paths) {
            if (!self::promisedAtLastTag($projectRoot . '/packages/' . $package)) {
                continue;
            }
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_BC_GATE,
                id: "bc_gate:{$package}",
                reason: 'the package marked @api at its last tag, which the release refuses to break unless it was deprecated 90 days before; checked against that tag now instead of at the cut',
                triggeredBy: $paths,
                filePath: "packages/{$package}",
            );
        }

        return $targets;
    }

    /**
     * Whether src/ at the package's last tag holds an @api, as the gate decides
     * it (the newest 20* tag by creation date, then git grep). A tag list or a
     * search git cannot answer counts as yes.
     */
    private static function promisedAtLastTag(string $packageDir): bool
    {
        [$status, $tags] = self::git($packageDir, ['tag', '--sort=-creatordate', '--list', '20*']);
        if ($status !== 0) {
            return true;
        }
        $tag = trim((string) strtok($tags, "\n"));
        if ($tag === '') {
            return false; // never released: nothing promised
        }
        [$grep] = self::git($packageDir, ['grep', '-q', '-e', '@api', $tag, '--', 'src']);

        return $grep !== 1;
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string} exit status and stdout; -1 when git could not start
     */
    private static function git(string $dir, array $arguments): array
    {
        $process = @proc_open(['git', '-C', $dir, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [-1, ''];
        }
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }
}
