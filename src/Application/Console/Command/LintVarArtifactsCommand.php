<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Evidence\VarArtifactScan;
use Semitexa\Dev\Application\Service\Ai\Verify\DirtyWorkspaceScanner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fail when git would commit runtime output from a var/ directory — a
 * screenshot, a request trace, an export. ai:verify runs it whenever a changed
 * path lies under var/ ({@see VarArtifactScan}).
 */
#[AsCommand(name: 'lint:var-artifacts', description: 'Fail when git would commit runtime output (screenshots, traces, exports) from a var/ directory')]
final class LintVarArtifactsCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('lint:var-artifacts');
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scanner = new DirtyWorkspaceScanner($this->getProjectRoot());
        $offending = VarArtifactScan::offending($scanner->changedFiles());

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['ok' => $offending === [], 'paths' => $offending], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $offending === [] ? self::SUCCESS : self::FAILURE;
        }
        foreach ($offending as $path) {
            $output->writeln('  ' . $path);
        }
        // The last line is what ai:verify reports as the signal.
        $output->writeln($offending === []
            ? 'lint:var-artifacts → git would commit nothing from var/'
            : sprintf(
                'lint:var-artifacts → git would commit %d runtime file(s) from var/, first %s: add the directory to .gitignore, or keep evidence in var/evidence/ (ai:evidence add)',
                count($offending),
                $offending[0],
            ));

        return $offending === [] ? self::SUCCESS : self::FAILURE;
    }
}
