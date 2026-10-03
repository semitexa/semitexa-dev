<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Dev\Application\Service\Ai\Evidence\VarArtifactScan;

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

        // Runtime output git would take as source: a screenshot or a trace in a
        // var/ folder the .gitignore does not list. Decided by the path alone —
        // git status already left out everything that IS ignored.
        $var = array_values(array_filter(
            array_map(static fn (ChangedFile $f): string => $f->path, $changedFiles),
            static fn (string $path): bool => VarArtifactScan::concerns($path),
        ));
        if ($var !== []) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_LINT,
                id: 'lint:var-artifacts',
                reason: 'a changed path under var/ is runtime output git would commit — screenshots and traces have reached public commits this way',
                triggeredBy: $var,
                commandName: 'lint:var-artifacts',
                // The selected paths, not only the dirty tree: a screenshot
                // committed in the range under review is in no `git status`.
                commandInput: ['--path' => array_values(array_map(
                    static fn (ChangedFile $f): string => $f->path,
                    array_filter($changedFiles, static fn (ChangedFile $f): bool => $f->status !== ChangedFile::STATUS_DELETED && VarArtifactScan::concerns($f->path)),
                ))],
            );
        }

        // A changed test may check less than it did at HEAD: removed, loosened,
        // skipped. Every scope, because it is one `git show` per test file and
        // because "the tests pass" is exactly the claim it exists to check.
        // A test renamed away from *Test.php still took its checks with it.
        $tests = array_values(array_map(
            static fn (ChangedFile $f): string => $f->path,
            array_filter($changedFiles, static fn (ChangedFile $f): bool => str_ends_with($f->path, 'Test.php')
                || str_ends_with((string) $f->originalPath, 'Test.php')),
        ));
        $renames = array_values(array_filter($changedFiles, static fn (ChangedFile $f): bool => $f->originalPath !== null && $f->originalPath !== ''));
        if ($tests !== []) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_LINT,
                id: 'lint:test-integrity',
                reason: 'a changed test may now check less than at HEAD — removed, loosened or skipped — which turns a suite green without making the code right',
                triggeredBy: $tests,
                commandName: 'lint:test-integrity',
                // Every path of the change, not only the tests: a deleted test
                // file says why on an added line of whichever file replaced it.
                commandInput: ['--path' => array_map(static fn (ChangedFile $f): string => $f->path, $changedFiles)]
                    + ($renames === [] ? [] : [
                        '--renamed-from' => array_map(static fn (ChangedFile $f): string => (string) $f->originalPath, $renames),
                        '--renamed-to'   => array_map(static fn (ChangedFile $f): string => $f->path, $renames),
                    ]),
            );
        }

        if ($effectiveScope === VerificationPlan::SCOPE_MINIMAL) {
            return $targets;
        }

        // What an agent is told to run and open can stop existing from either
        // side: the instructions change, or the file they name is deleted or
        // renamed. Same expensive tier as the other docs gates (a console boot
        // for the truth index), so not at minimal.
        $instructionTriggers = array_values(array_map(
            static fn (ChangedFile $f): string => $f->path,
            array_filter($changedFiles, static fn (ChangedFile $f): bool => self::isInstruction($f->path)
                || $f->status === ChangedFile::STATUS_DELETED
                || $f->status === ChangedFile::STATUS_RENAMED),
        ));
        if ($instructionTriggers !== []) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_DOCS,
                id: 'docs:instructions',
                reason: 'an instruction to an agent (AGENTS.md, CLAUDE.md, AI_NOTES.md, a skill) naming a command or file that no longer exists is followed anyway',
                triggeredBy: $instructionTriggers,
                commandName: 'docs:lint',
                commandInput: ['--instructions' => true],
            );
        }

        // Tests are not part of the minimal contract, and a consumer project
        // has no workspace tree to ratchet — the suite only exists here.
        if (!is_dir($this->projectRoot . '/' . self::RATCHET_SUITE)) {
            return $targets;
        }

        // The old path too: a PHP file renamed to something else still changed
        // the PHP tree (a test that vanished can shrink what a ratchet sees).
        $php = array_values(array_filter(
            array_merge(...array_map(static fn (ChangedFile $f): array => array_filter([$f->path, $f->originalPath]), $changedFiles)),
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

    /**
     * Read by an agent as instructions: a markdown file at the project root, or
     * a skill (its installed copy or, in the workspace, its canonical source).
     * `docs:lint --instructions` reads the same set. In the workspace the root
     * is not a repository, so an edit there is seen through the versioned copy
     * of the scaffold docs in semitexa-ultimate.
     */
    public static function isInstruction(string $path): bool
    {
        if (!str_ends_with(strtolower($path), '.md')) {
            return false;
        }

        return !str_contains($path, '/')
            || preg_match('#^packages/semitexa-ultimate/[^/]+$#', $path) === 1
            || str_starts_with($path, '.claude/skills/')
            || str_starts_with($path, 'packages/semitexa-dev/resources/skills/');
    }
}
