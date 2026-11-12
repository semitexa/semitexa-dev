<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\DirtyWorkspaceScanner;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\PushHook;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\ReceiptForCommit;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Does a passing ai:verify run vouch for each of these commits? The push gate's
 * question (tk-prepush-refuses-red-verify): a red run was pushed three times
 * because a chain printed the verdict instead of checking it. --install-hook
 * writes a pre-push hook into every workspace repository that asks this for the
 * commits a push would send and refuses the push when one has no receipt.
 */
#[AsCommand(name: 'ai:verify:covers', description: 'Exit 0 when a passing ai:verify receipt covers each commit; --install-hook adds the pre-push gate to every workspace repository')]
final class AiVerifyCoversCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('ai:verify:covers');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('commits', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Commit shas in the repository named by --repo')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, "Workspace-relative repository: packages/<name>, or '' for the project root", '')
            ->addOption('install-hook', null, InputOption::VALUE_NONE, 'Write the pre-push gate into every workspace repository (a hook written by someone else is left alone and named)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->getProjectRoot();
        if ((bool) $input->getOption('install-hook')) {
            $result = (new PushHook())->install((new DirtyWorkspaceScanner($root))->repositories());
            foreach ($result as $repo => $outcome) {
                $output->writeln(sprintf('%-40s %s', $repo === '' ? '(project root)' : $repo, $outcome));
            }

            return in_array(PushHook::FOREIGN, $result, true) ? self::FAILURE : self::SUCCESS;
        }

        $repo = (string) $input->getOption('repo');
        /** @var list<string> $commits */
        $commits = array_values(array_filter((array) $input->getArgument('commits'), static fn ($c): bool => is_string($c) && $c !== ''));
        $finder = new ReceiptForCommit($root);
        $found = [];
        foreach ($commits as $sha) {
            $found[$sha] = $finder->find($repo, $sha);
        }
        $missing = array_keys(array_filter($found, static fn (?string $id): bool => $id === null));

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['artifact' => 'semitexa-dev.verify-covers/v1', 'repo' => $repo, 'covered' => $missing === [], 'receipts' => $found], JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        } else {
            foreach ($found as $sha => $id) {
                $output->writeln(sprintf('%s  %s', substr($sha, 0, 12), $id ?? 'NO passing ai:verify receipt checked these bytes'));
            }
            if ($missing !== []) {
                $output->writeln('');
                $output->writeln('Run ai:verify --files=<the commit\'s files>, see it pass, and commit again (amend if the bytes changed). Pushing without a passing run is the operator\'s call: git push --no-verify.');
            }
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }
}
