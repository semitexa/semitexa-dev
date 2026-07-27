<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Report\DefectReport;
use Semitexa\Dev\Application\Service\Ai\Report\IssueReporter;
use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;

final class IssueReporterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-report-' . uniqid();
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $dir = $this->root . '/var/ai-report';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
        }
        @rmdir($dir);
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    private function report(): DefectReport
    {
        return DefectReport::create(
            title: 'DeleteQuery renames bound parameters and breaks test doubles',
            summary: 'Routing deletes through the builder changed :__pk to a generated name, so doubles keyed on it stop matching.',
            evidence: "bin/semitexa test:run\n  1) DeleteArticleMutationHandlerTest\n  the row must actually be gone",
            workaround: 'Matched on bound values instead of the placeholder name.',
            package: 'semitexa-orm',
            versions: ['semitexa/orm' => '2026.07.25.0821'],
        );
    }

    #[Test]
    public function an_unauthenticated_gh_does_not_lose_the_report(): void
    {
        // The failure mode this tool exists to prevent must not apply to the
        // tool itself: no transport means a draft on disk, never a silent drop.
        $reporter = new IssueReporter($this->root, new FakeRunner(['gh auth status' => ['exit' => 1, 'output' => 'not logged in']]));

        self::assertFalse($reporter->canPublish());

        $path = $reporter->saveDraft($this->report(), 'semitexa/semitexa-core');
        self::assertFileExists($path);
        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('gh issue create --repo semitexa/semitexa-core', $contents);
        self::assertStringContainsString('Workaround applied', $contents);
    }

    /**
     * Review finding on PR #40: the draft filename came from the fingerprint,
     * which is title-derived and deliberately collision-prone because that is
     * what makes de-duplication work. Reusing it verbatim turned the same
     * property into silent data loss — the exact failure this class exists to
     * prevent.
     */
    #[Test]
    public function a_second_draft_does_not_overwrite_the_first(): void
    {
        $reporter = new IssueReporter($this->root, new FakeRunner());

        $first  = $reporter->saveDraft($this->report(), 'semitexa/semitexa-core');
        $second = $reporter->saveDraft($this->report(), 'semitexa/semitexa-core');

        self::assertNotSame($first, $second);
        self::assertFileExists($first);
        self::assertFileExists($second);
    }

    #[Test]
    public function a_sighting_keeps_backticked_evidence_inside_its_fence(): void
    {
        // Sightings post automatically with no operator review, so the fence has
        // to survive the content without anyone checking it.
        $report = DefectReport::create(
            title: 'Fence handling in sightings',
            summary: 'Evidence containing a fenced block used to break out of the wrapper.',
            evidence: "step one\n```\ninner fence\n```\nstep two",
            workaround: 'Escaped it by hand.',
        );
        $runner = new FakeRunner(['gh issue comment' => ['exit' => 0, 'output' => 'ok']]);

        (new IssueReporter($this->root, $runner))->addSighting($report, 'semitexa/semitexa-core', 7);

        $body = $runner->lastCommandString();
        self::assertStringContainsString('````', $body, 'the wrapper fence must outgrow the inner one');
    }

    #[Test]
    public function an_existing_issue_is_found_so_a_duplicate_is_not_opened(): void
    {
        $runner = new FakeRunner([
            'gh issue list' => ['exit' => 0, 'output' => json_encode([
                ['number' => 12, 'title' => 'DeleteQuery renames bound parameters', 'url' => 'https://github.com/semitexa/semitexa-core/issues/12'],
            ])],
        ]);

        $found = (new IssueReporter($this->root, $runner))->findDuplicates($this->report(), 'semitexa/semitexa-core');

        self::assertCount(1, $found);
        self::assertSame(12, $found[0]['number']);
    }

    #[Test]
    public function a_failed_search_reports_no_duplicates_rather_than_crashing(): void
    {
        // A search outage must not block the report — worst case is a duplicate,
        // which is recoverable; a lost defect is not.
        $runner = new FakeRunner(['gh issue list' => ['exit' => 1, 'output' => 'API rate limit exceeded']]);

        self::assertSame([], (new IssueReporter($this->root, $runner))->findDuplicates($this->report(), 'semitexa/semitexa-core'));
    }

    #[Test]
    public function a_second_sighting_carries_the_version_it_recurred_on(): void
    {
        $runner = new FakeRunner(['gh issue comment' => ['exit' => 0, 'output' => 'https://github.com/semitexa/semitexa-core/issues/12#issuecomment-1']]);

        $result = (new IssueReporter($this->root, $runner))->addSighting($this->report(), 'semitexa/semitexa-core', 12);

        self::assertTrue($result['ok']);
        $body = $runner->lastCommandString();
        self::assertStringContainsString('semitexa/orm 2026.07.25.0821', $body);
        self::assertStringContainsString('Workaround applied', $body);
    }

    #[Test]
    public function publishing_surfaces_the_issue_url(): void
    {
        $runner = new FakeRunner(['gh issue create' => ['exit' => 0, 'output' => "https://github.com/semitexa/semitexa-core/issues/13\n"]]);

        $result = (new IssueReporter($this->root, $runner))->publish($this->report(), 'semitexa/semitexa-core');

        self::assertTrue($result['ok']);
        self::assertSame('https://github.com/semitexa/semitexa-core/issues/13', $result['url']);
    }
}

/** Matches on a command prefix so tests do not pin exact argv ordering. */
final class FakeRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: string}> */
    public array $calls = [];

    /** @param array<string, array{exit: int, output: string}> $responses */
    public function __construct(private readonly array $responses = []) {}

    public function run(array $command, string $cwd): array
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd];
        $joined = implode(' ', $command);

        foreach ($this->responses as $prefix => $response) {
            if (str_starts_with($joined, $prefix)) {
                return $response;
            }
        }

        return ['exit' => 0, 'output' => ''];
    }

    public function lastCommandString(): string
    {
        $last = $this->calls[array_key_last($this->calls)] ?? ['command' => []];

        return implode(' ', $last['command']);
    }
}
