<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\DirtyWorkspaceScanner;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\TestChangeFinding;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\TestChangeScan;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\WorkspaceRevisions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fail when a change makes the tests check less than they did at HEAD and does
 * not say why: a test file or test removed, assertions removed, a value check
 * loosened to a shape check, a skip added. ai:verify runs it whenever a
 * changed path is a test ({@see TestChangeScan}).
 *
 * Considered and NOT shipped: flagging a new assertion whose expected literal
 * the same change wrote into production code. MEASURED 2026-10-03 over two
 * months of this workspace, it fired in 77 of 856 commits (159 hits), and
 * nearly every one was a value a specification defines — a header name, a
 * status code, a rule identifier — that the code and the test rightly share.
 */
#[AsCommand(name: 'lint:test-integrity', description: 'Fail when a change removes or weakens test checks without saying why')]
final class LintTestIntegrityCommand extends BaseCommand
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: a red suite can be turned green by deleting or loosening the test instead of fixing the code, and "all tests pass" is then true and worthless; a reason on an added line puts the decision in the diff, where a reviewer reads it. Policy since 2026-10-03 (ep-verify-agent-false-greens); no incident on record. Measured that day: 23 of 955 test-file changes in two months lost checks.';

    public function __construct()
    {
        parent::__construct('lint:test-integrity');
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope')
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Workspace-relative path of the change (ai:verify passes the paths it selected); default: every uncommitted change');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->getProjectRoot();
        $paths = array_values(array_filter((array) $input->getOption('path'), is_string(...)));
        if ($paths === []) {
            $paths = array_column((new DirtyWorkspaceScanner($root))->changedFiles(), 'path');
        }
        $revisions = new WorkspaceRevisions($root);
        $result = (new TestChangeScan($revisions->committed(...), $revisions->current(...)))->scan($paths);
        $findings = $result['findings'];
        $accepted = $result['accepted'];

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode([
                'ok'       => $findings === [],
                'findings' => array_map(static fn (TestChangeFinding $f): array => $f->toArray(), $findings),
                'accepted' => array_map(static fn (TestChangeFinding $f): array => $f->toArray(), $accepted),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($findings as $finding) {
            $output->writeln("  {$finding->path}: {$finding->message}");
        }
        foreach ($accepted as $finding) {
            $output->writeln("  accepted {$finding->path}: {$finding->message} — {$finding->acceptedReason}");
        }
        // The last line is what ai:verify reports as the signal.
        if ($findings !== []) {
            // What to do comes first: the signal is cut at 240 characters, and
            // the finding itself is printed in full above.
            $output->writeln(sprintf(
                'lint:test-integrity → %d test change(s) check less than HEAD: restore the checks, or say why on an added line // %s <reason>; first %s: %s',
                count($findings),
                TestChangeScan::MARKER,
                $findings[0]->path,
                $findings[0]->message,
            ));

            return self::FAILURE;
        }
        $output->writeln($accepted === []
            ? 'lint:test-integrity → no test checks less than HEAD'
            : sprintf('lint:test-integrity → %d weakening(s) accepted with a reason in the diff: %s', count($accepted), $accepted[0]->acceptedReason));

        return self::SUCCESS;
    }
}
