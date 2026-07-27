<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Report;

use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\ShellProcessRunner;

/**
 * Publishes a {@see DefectReport} to the Semitexa issue tracker through the
 * locally authenticated `gh` CLI.
 *
 * Deliberately fail-soft: when `gh` is missing or unauthenticated the report is
 * written to disk instead of being dropped. A tool built to stop losing defects
 * must not lose them itself when its transport is unavailable.
 */
final class IssueReporter
{
    public const string DEFAULT_REPO = 'semitexa/semitexa-core';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner = new ShellProcessRunner(),
    ) {}

    /** `gh` present AND authenticated. */
    public function canPublish(): bool
    {
        $probe = $this->runner->run(['gh', 'auth', 'status'], $this->projectRoot);

        return $probe['exit'] === 0;
    }

    /**
     * Existing open issues that look like the same defect.
     *
     * @return list<array{number: int, title: string, url: string}>
     */
    public function findDuplicates(DefectReport $report, string $repo): array
    {
        $terms = $report->searchTerms();
        if ($terms === '') {
            return [];
        }

        $result = $this->runner->run([
            'gh', 'issue', 'list',
            '--repo', $repo,
            '--state', 'open',
            '--search', $terms,
            '--limit', '5',
            '--json', 'number,title,url',
        ], $this->projectRoot);

        if ($result['exit'] !== 0) {
            return [];
        }

        $decoded = json_decode($result['output'], true);
        if (!is_array($decoded)) {
            return [];
        }

        $issues = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['number'], $row['title'], $row['url'])) {
                continue;
            }
            $issues[] = [
                'number' => (int) $row['number'],
                'title'  => (string) $row['title'],
                'url'    => (string) $row['url'],
            ];
        }

        return $issues;
    }

    /** @return array{ok: bool, url: string, error: string} */
    public function publish(DefectReport $report, string $repo): array
    {
        $bodyFile = $this->writeTempBody($report);

        try {
            $result = $this->runner->run([
                'gh', 'issue', 'create',
                '--repo', $repo,
                '--title', $report->title,
                '--body-file', $bodyFile,
            ], $this->projectRoot);
        } finally {
            @unlink($bodyFile);
        }

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'url' => '', 'error' => $result['output']];
        }

        return ['ok' => true, 'url' => trim($result['output']), 'error' => ''];
    }

    /**
     * Add this sighting to an existing issue rather than opening a duplicate.
     *
     * @return array{ok: bool, url: string, error: string}
     */
    public function addSighting(DefectReport $report, string $repo, int $issueNumber): array
    {
        $versions = $report->versions === []
            ? 'unknown versions'
            : implode(', ', array_map(
                static fn (string $name, string $v): string => $name . ' ' . $v,
                array_keys($report->versions),
                array_values($report->versions),
            ));

        $body = "Seen again on {$versions}.\n\n"
            . "**Workaround applied:** {$report->workaround}\n\n"
            . "<details><summary>Evidence</summary>\n\n```\n{$report->evidence}\n```\n\n</details>";

        $result = $this->runner->run([
            'gh', 'issue', 'comment', (string) $issueNumber,
            '--repo', $repo,
            '--body', $body,
        ], $this->projectRoot);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'url' => '', 'error' => $result['output']];
        }

        return ['ok' => true, 'url' => trim($result['output']), 'error' => ''];
    }

    /**
     * Fallback when publishing is impossible. Returns the path written.
     */
    public function saveDraft(DefectReport $report, string $repo): string
    {
        $dir = $this->projectRoot . '/var/ai-report';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $report->fingerprint());
        $slug = trim($slug, '-');
        $slug = $slug === '' ? 'report' : mb_substr($slug, 0, 60);
        $path = sprintf('%s/%s.md', $dir, $slug);

        $contents = "# {$report->title}\n\n"
            . "> Draft — `gh` was unavailable or unauthenticated, so this was not published.\n"
            . "> File it with:\n"
            . "> `gh issue create --repo {$repo} --title \"{$report->title}\" --body-file " . $path . "`\n\n"
            . $report->toMarkdown() . "\n";

        file_put_contents($path, $contents);

        return $path;
    }

    private function writeTempBody(DefectReport $report): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'semitexa-report-');
        file_put_contents($file, $report->toMarkdown());

        return $file;
    }
}
