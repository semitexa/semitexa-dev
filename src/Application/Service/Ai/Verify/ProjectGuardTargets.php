<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Checks that guard the whole project, whichever file changed.
 *
 * Every other target is chosen by what was touched: a lint by file kind, a test
 * by a name that matches. A ratchet is not about the file you touched — it is
 * about the tree getting worse — so name matching never selected one. Grow
 * SseServer.php and the budget test that names it did not run; the regression
 * surfaced days later, in a release, where nobody could say who caused it.
 */
final class ProjectGuardTargets
{
    /**
     * The whole-tree ratchets: class size budgets, static container access,
     * packages without tests, coroutine state leaks. Measured at 1.3 s for the
     * directory — cheap enough to run on every verification that runs tests.
     */
    public const RATCHET_SUITE = 'packages/semitexa-dev/tests/Unit/Structure';

    public function __construct(private readonly string $projectRoot) {}

    /**
     * @param list<ChangedFile> $changedFiles
     *
     * @return list<VerificationTarget>
     */
    public function targets(array $changedFiles, string $effectiveScope): array
    {
        $targets = [
            new VerificationTarget(
                type: VerificationTarget::TYPE_SKILL_COPIES,
                id: 'skill_copies:project',
                reason: 'whole agent skills are duplicated into bin/ and one directory per agent runtime, none of which is version-controlled; an edited copy is silently lost on the next sync',
                triggeredBy: [],
                filePath: null,
            ),
            // The release copies the ROOT phpstan files into its clone and gates
            // on them. Root is not versioned: a regenerated baseline there, never
            // adopted back, would set the release's phpstan ceiling from a file
            // no reviewer saw. phpstan-sync.sh --check existed for exactly this
            // and nothing ran it.
            new VerificationTarget(
                type: VerificationTarget::TYPE_SKILL_COPIES,
                id: 'phpstan_copies:project',
                reason: 'the release gates on the root phpstan config and baseline, which are unversioned copies of packages/semitexa-dev/resources/phpstan/; drift means the gate measures against something nobody reviewed',
                triggeredBy: [],
                filePath: 'packages/semitexa-dev/resources/phpstan-sync.sh',
            ),
        ];

        // Tests are not part of the minimal contract, and a consumer project
        // has no workspace tree to ratchet — the suite only exists here.
        if ($effectiveScope === VerificationPlan::SCOPE_MINIMAL || !is_dir($this->projectRoot . '/' . self::RATCHET_SUITE)) {
            return $targets;
        }

        $php = array_values(array_filter(
            array_map(static fn (ChangedFile $f): string => $f->path, $changedFiles),
            static fn (string $path): bool => str_ends_with($path, '.php')
                && (str_starts_with($path, 'packages/') || str_starts_with($path, 'src/modules/')),
        ));
        if ($php !== []) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_PHPUNIT,
                id: 'phpunit:' . self::RATCHET_SUITE,
                reason: 'whole-tree ratchets — size budgets, static container access, untested packages, coroutine state — hold for every PHP change, not only a file whose name matches',
                triggeredBy: $php,
                // The quality ledger gate holds a verification to the repos it
                // changed: another agent's uncommitted regression elsewhere in
                // the shared tree must not turn this agent's run red.
                commandInput: ['SEMITEXA_QUALITY_SCOPE' => implode(',', self::reposOf($php))],
                filePath: self::RATCHET_SUITE,
                testFilter: null,
            );
        }

        return $targets;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string> packages/<name> or src/modules/<Name>
     */
    private static function reposOf(array $paths): array
    {
        $repos = [];
        foreach ($paths as $path) {
            if (preg_match('#^(packages/[^/]+|src/modules/[^/]+)/#', $path, $m) === 1) {
                $repos[$m[1]] = true;
            }
        }

        return array_keys($repos);
    }
}
