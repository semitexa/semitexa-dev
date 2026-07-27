<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Report\DefectReport;
use Semitexa\Dev\Application\Service\Ai\Report\IssueReporter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 *   bin/semitexa ai:report --title="..." --summary="..." --evidence="..." --workaround="..."
 *
 * Publishes a framework defect to the Semitexa issue tracker.
 *
 * Why this exists: an agent that hits a Semitexa defect works around it, and the
 * workaround becomes permanent and invisible. Directive 4 says to externalize
 * state, but `ai:epic` lives inside the consumer's project and the framework
 * never sees it — the directive breaks at the project boundary. This carries the
 * defect across it.
 *
 * Three properties keep the channel usable rather than noisy:
 *
 *   - Evidence is mandatory. An agent that merely suspects a bug is usually
 *     looking at its own mistake.
 *   - Duplicates are searched before anything is created; a second sighting
 *     becomes a comment on the existing issue, carrying the version it recurred on.
 *   - Nothing is published without the operator seeing it first. Filing to a
 *     public tracker under someone's `gh` identity is an outward-facing act.
 */
#[AsCommand(name: 'ai:report', description: 'Report a Semitexa framework defect (and its workaround) as a GitHub issue')]
final class AiReportCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('ai:report');
    }

    protected function configure(): void
    {
        $this
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'One-line defect title')
            ->addOption('summary', null, InputOption::VALUE_REQUIRED, 'What broke, in at least a sentence')
            ->addOption('evidence', null, InputOption::VALUE_REQUIRED, 'Command and output proving the defect is real')
            ->addOption('workaround', null, InputOption::VALUE_REQUIRED, 'What you did instead (or "none — blocked")')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Affected package, e.g. semitexa-orm')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Target repository', IssueReporter::DEFAULT_REPO)
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->addOption('draft', null, InputOption::VALUE_NONE, 'Write the draft locally, never publish')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->getProjectRoot();
        $json = (bool) $input->getOption('json');
        $repo = (string) $input->getOption('repo');

        try {
            $report = DefectReport::create(
                title:      (string) $input->getOption('title'),
                summary:    (string) $input->getOption('summary'),
                evidence:   (string) $input->getOption('evidence'),
                workaround: (string) $input->getOption('workaround'),
                package:    $input->getOption('package') !== null ? (string) $input->getOption('package') : null,
                versions:   $this->installedVersions($root),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($output, $json, $e->getMessage());
        }

        $reporter = new IssueReporter($root);

        if ((bool) $input->getOption('draft') || !$reporter->canPublish()) {
            $path   = $reporter->saveDraft($report, $repo);
            $reason = (bool) $input->getOption('draft')
                ? 'draft requested'
                : 'gh is unavailable or unauthenticated';

            return $this->emit($output, $json, [
                'status' => 'drafted',
                'reason' => $reason,
                'path'   => $path,
                'repo'   => $repo,
            ], sprintf("Not published (%s).\nDraft saved to %s", $reason, $path));
        }

        $duplicates = $reporter->findDuplicates($report, $repo);
        if ($duplicates !== []) {
            $first  = $duplicates[0];
            $result = $reporter->addSighting($report, $repo, $first['number']);

            if (!$result['ok']) {
                return $this->fail($output, $json, 'Could not comment on #' . $first['number'] . ': ' . $result['error']);
            }

            return $this->emit($output, $json, [
                'status' => 'sighting-added',
                'issue'  => $first['number'],
                'url'    => $first['url'],
                'repo'   => $repo,
            ], sprintf('Existing issue #%d covers this — added a sighting instead of a duplicate: %s', $first['number'], $first['url']));
        }

        // --json must never imply consent. It is the flag an agent reaches for
        // to get parseable output, and treating it as "no prompt needed" made
        // the safety property this command advertises bypassable by the most
        // common caller. In JSON mode consent has to be stated with --yes.
        if (self::refusesUnattendedPublish($json, (bool) $input->getOption('yes'))) {
            $path = $reporter->saveDraft($report, $repo);

            return $this->fail(
                $output,
                true,
                'Refusing to publish unattended: --json does not imply consent. '
                . 'Re-run with --yes to publish, or file the draft kept at ' . $path,
            );
        }

        if (!$input->getOption('yes')) {
            $output->writeln('');
            $output->writeln(sprintf('<info>About to open an issue in %s:</info>', $repo));
            $output->writeln('');
            $output->writeln('<comment>' . $report->title . '</comment>');
            $output->writeln('');
            $output->writeln($report->toMarkdown());
            $output->writeln('');

            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion('Publish this to the public tracker? [y/N] ', false);
            if ($helper->ask($input, $output, $question) !== true) {
                $path = $reporter->saveDraft($report, $repo);
                $output->writeln(sprintf('Not published. Draft kept at %s', $path));

                return self::SUCCESS;
            }
        }

        $result = $reporter->publish($report, $repo);
        if (!$result['ok']) {
            $path = $reporter->saveDraft($report, $repo);

            return $this->fail($output, $json, 'Publishing failed: ' . $result['error'] . ' (draft kept at ' . $path . ')');
        }

        return $this->emit($output, $json, [
            'status' => 'published',
            'url'    => $result['url'],
            'repo'   => $repo,
        ], 'Reported: ' . $result['url']);
    }

    /**
     * Whether an unattended publish must be refused.
     *
     * Extracted so the gate is provable on its own: without `gh` on PATH the
     * draft branch short-circuits before this point is ever reached, which
     * would leave a security property rated critical in review verifiable only
     * by reading it.
     *
     * `--json` is the flag an agent reaches for to get parseable output.
     * Treating it as "no prompt needed" is what made the guarantee this command
     * advertises bypassable by its most likely caller.
     */
    public static function refusesUnattendedPublish(bool $json, bool $yes): bool
    {
        return $json && !$yes;
    }

    /**
     * Installed semitexa/* versions, so a maintainer can tell whether the defect
     * is still live. Read from composer.lock rather than the packages tree —
     * this runs in a consumer project, not the framework workspace.
     *
     * @return array<string, string>
     */
    private function installedVersions(string $root): array
    {
        $lock = $root . '/composer.lock';
        if (!is_file($lock)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($lock), true);
        if (!is_array($decoded)) {
            return [];
        }

        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($decoded[$section] ?? [] as $package) {
                if (!is_array($package) || !isset($package['name'], $package['version'])) {
                    continue;
                }
                $name = (string) $package['name'];
                if (str_starts_with($name, 'semitexa/')) {
                    $versions[$name] = (string) $package['version'];
                }
            }
        }
        ksort($versions);

        return $versions;
    }

    /** @param array<string, mixed> $payload */
    private function emit(OutputInterface $output, bool $json, array $payload, string $text): int
    {
        if ($json) {
            $output->writeln((string) json_encode(['artifact' => 'semitexa.ai-report/v1'] + $payload));
        } else {
            $output->writeln($text);
        }

        return self::SUCCESS;
    }

    private function fail(OutputInterface $output, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln((string) json_encode(['artifact' => 'semitexa.ai-report/v1', 'status' => 'error', 'error' => $message]));
        } else {
            $output->writeln('<error>' . $message . '</error>');
        }

        return self::FAILURE;
    }
}
