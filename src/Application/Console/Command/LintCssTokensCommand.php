<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\CssTokenScan;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Fail on a --ui-* token that is used and declared nowhere ({@see CssTokenScan}). */
#[AsCommand(name: 'lint:css-tokens', description: 'Fail when CSS or a template under src/ uses a --ui-* token that no stylesheet under src/, packages/ or vendor/semitexa/ declares')]
final class LintCssTokensCommand extends BaseCommand
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: CSS says nothing about a token that does not exist, it falls to the fallback or drops the property. Learned 2026-11-07: an invented --ui-color-bg kept a page white under light text in dark mode, a contrast of 1.2 to 1, with every test green. Read the names from a skin\'s tokens.css.';

    public function __construct()
    {
        parent::__construct('lint:css-tokens');
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $unknown = CssTokenScan::unknown($this->getProjectRoot());
        } catch (\RuntimeException $e) {
            $output->writeln((bool) $input->getOption('json')
                ? (string) json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES)
                : 'lint:css-tokens → ' . $e->getMessage() . ': nothing was checked', OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['ok' => $unknown === [], 'unknown' => $unknown], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return $unknown === [] ? self::SUCCESS : self::FAILURE;
        }
        foreach ($unknown as $file => $tokens) {
            $output->writeln(sprintf('  %s: %s', $file, implode(', ', $tokens)));
        }
        // The last line is what ai:verify reports as the signal.
        $first = array_key_first($unknown);
        $output->writeln($first === null
            ? 'lint:css-tokens → every --ui-* token in use is declared'
            : sprintf('lint:css-tokens → %d file(s) use a --ui-* token declared nowhere, first %s: %s', count($unknown), $first, $unknown[$first][0]));

        return $unknown === [] ? self::SUCCESS : self::FAILURE;
    }
}
