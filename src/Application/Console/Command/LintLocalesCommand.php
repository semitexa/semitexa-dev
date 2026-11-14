<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\LocalePackScan;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Fail on a locale pack the loader would skip or thin out, or one missing keys its siblings have ({@see LocalePackScan}). */
#[AsCommand(name: 'lint:locales', description: 'Fail when a module or package locale pack (View/locales/*.json) is not a JSON object of strings and plural maps, or lacks a key another language of that directory has')]
final class LintLocalesCommand extends BaseCommand
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: a locale pack fails silently. The loader skips a file that is not a JSON object and drops a value that is not a string or plural map, and a key one language lacks is printed as the key itself. Learned 2026-11-14: a consumer\'s Marketplace packs could not be checked by ai:verify at all, so its tasks never got past incomplete.';

    public function __construct()
    {
        parent::__construct('lint:locales');
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $problems = LocalePackScan::problems($this->getProjectRoot());

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['ok' => $problems === [], 'problems' => $problems], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }
        foreach ($problems as $file => $found) {
            foreach ($found as $problem) {
                $output->writeln(sprintf('  %s %s', $file, $problem));
            }
        }
        $first = array_key_first($problems);
        $output->writeln($first === null
            ? 'lint:locales → every locale pack loads whole and carries the keys its siblings do'
            : sprintf('lint:locales → %d pack(s) with a problem, first %s %s', count($problems), $first, $problems[$first][0]));

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
