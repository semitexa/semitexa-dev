<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\Shell\ShellFallbackScan;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Fail on a POSIX sh fallback the shell can never reach ({@see ShellFallbackScan}). */
#[AsCommand(name: 'lint:shell-fallbacks', description: 'Fail when a #!/bin/sh script reads the status of a special builtin (:, exec, set, …) that carries a file redirection (an if/while condition, a `!`, an operand of && / || with another after it): a redirection that fails there exits the shell, so the handling never runs')]
final class LintShellFallbacksCommand extends BaseCommand
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: in POSIX sh (dash, BusyBox, the image\'s /bin/sh) a redirection that fails on a special builtin exits the shell instead of failing the command, so `if : > "$f" 2>/dev/null; then …; else fallback; fi` never reaches its fallback, and the line still counts as covered. Learned 2026-11-19: review found the var/log fallback of bin/semitexa written exactly so; no test and no line coverage could have shown it.';

    public function __construct()
    {
        parent::__construct('lint:shell-fallbacks');
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check these scripts (relative to the project root) instead of every script of the project');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'With --file: check every script of the project as well');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // An empty value is kept: the scan reports it, where dropping it turned `--file=""` into a pass over other files.
        $files = array_values(array_filter((array) $input->getOption('file'), is_string(...)));
        $problems = ShellFallbackScan::problems($this->getProjectRoot(), $files, (bool) $input->getOption('all'));

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['ok' => $problems === [], 'problems' => $problems], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }
        foreach ($problems as $file => $found) {
            foreach ($found as $problem) {
                $output->writeln(sprintf('  %s %s', $file, $problem));
            }
        }
        $first = array_key_first($problems);
        $output->writeln($first === null
            ? 'lint:shell-fallbacks → no POSIX sh script reads the status of a special builtin whose redirection can exit the shell'
            : sprintf('lint:shell-fallbacks → %d script(s) with a problem, first %s %s', count($problems), $first, $problems[$first][0]));

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
