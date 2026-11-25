<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\GraphExtractor\CrossFileProbes;
use Semitexa\Dev\Application\Service\Ai\Verify\GraphExtractor\GraphExtractorScan;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Fail on a project-graph extractor that reads beyond the file it was given ({@see GraphExtractorScan}). */
#[AsCommand(name: 'lint:graph-extractors', description: 'Fail when a project-graph extractor, the parser or a helper they import reads beyond the file being extracted (a filesystem call, include/require, reflection, a class probe such as class_exists or method_exists, a class instantiated by a run-time name) without an accept on that read that names its kind and the convergence test covering it')]
final class LintGraphExtractorsCommand extends BaseCommand
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: the probe makes an extracted fact depend on a file the incremental engine does not re-read, so a refresh drifts from a full build. Learned 2026-11-18: the review of the UI flow graph found renders_template edges that a refresh never added or removed.';

    public function __construct()
    {
        parent::__construct('lint:graph-extractors');
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check these PHP files (relative to the project root) instead of every extractor of the project; a file of the package that no extractor runs (a test, another class) makes it check them all');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'With --file: check every extractor of the project as well');
        $this->setHelp(
            "Reads the PHP under " . implode(' and ', GraphExtractorScan::DIRECTORIES) . ", and every class of the package those files import with `use`.\n\n"
            . "Accept one read with a comment on its line, or on the line above its statement:\n"
            . "  // " . GraphExtractorScan::MARKER . " <kind>[,<kind>] <TestClass>::<testMethod> <reason>\n"
            . "Kinds: " . implode(', ', CrossFileProbes::KINDS) . ". The test is a test method under " . GraphExtractorScan::TESTS . ".\n\n"
            . "Not seen: a function called through a variable (\$f()), a static call on a class named at run time (\$c::from()), unserialize(), "
            . "and a class of the package named only by its full name.\n"
            . "Flagged though they may read nothing: is_a, method_exists and property_exists given an object.",
        );
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // An empty value is kept: the scan reports it, where dropping it turned `--file=""` into a pass over other files.
        $files = array_values(array_filter((array) $input->getOption('file'), is_string(...)));
        $root = $this->getProjectRoot();
        $problems = GraphExtractorScan::problems($root, $files, (bool) $input->getOption('all'));
        // A project without the package's source (a consumer) has no extractor to read: said, so a pass is not taken for a reading.
        $nothing = $files === [] && !GraphExtractorScan::hasExtractors($root);

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['ok' => $problems === [], 'problems' => $problems] + ($nothing ? ['scanned' => 'nothing: ' . GraphExtractorScan::DIRECTORY . ' is not in this project'] : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }
        foreach ($problems as $file => $found) {
            foreach ($found as $problem) {
                $output->writeln(sprintf('  %s %s', $file, $problem));
            }
        }
        $first = array_key_first($problems);
        $output->writeln(match (true) {
            $first !== null => sprintf('lint:graph-extractors → %d file(s) with a problem, first %s %s', count($problems), $first, $problems[$first][0]),
            $nothing => 'lint:graph-extractors → nothing to scan: ' . GraphExtractorScan::DIRECTORY . ' is not in this project',
            default => 'lint:graph-extractors → nothing an extractor runs reads beyond the file it extracts without an accept that names a convergence test',
        });

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
