<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiReportCommand;
use Semitexa\Dev\Application\Service\Ai\Report\DefectReport;

final class DefectReportTest extends TestCase
{
    private function valid(array $overrides = []): DefectReport
    {
        return DefectReport::create(
            title:      $overrides['title']      ?? 'ai:verify reports pass while skipping lints',
            summary:    $overrides['summary']    ?? 'The planner names lints with a prefix no command declares, so they are skipped and the verdict stays green.',
            evidence:   $overrides['evidence']   ?? "bin/semitexa ai:verify --files=x.php\n  skipped lint  command semitexa:lint:di is not registered\n  VERDICT pass",
            workaround: $overrides['workaround'] ?? 'Ran the lints by hand after every change.',
            package:    $overrides['package']    ?? 'semitexa-dev',
            versions:   $overrides['versions']   ?? ['semitexa/dev' => '2026.07.25.0821'],
        );
    }

    #[Test]
    public function a_complete_report_is_accepted(): void
    {
        $report = $this->valid();

        self::assertStringContainsString('## Evidence', $report->toMarkdown());
        self::assertStringContainsString('## Workaround applied', $report->toMarkdown());
        self::assertStringContainsString('semitexa/dev', $report->toMarkdown());
    }

    #[Test]
    public function evidence_is_mandatory(): void
    {
        // The whole point of the gate: an agent that only suspects a defect is
        // usually looking at its own mistake.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Evidence is required');

        $this->valid(['evidence' => 'seems broken']);
    }

    #[Test]
    public function the_workaround_is_mandatory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('workaround');

        $this->valid(['workaround' => '']);
    }

    #[Test]
    public function a_title_alone_is_not_actionable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->valid(['summary' => 'broken']);
    }

    /**
     * Reports go to a PUBLIC tracker, from a machine that is usually not ours.
     */
    #[Test]
    public function a_credential_anywhere_in_the_report_blocks_it(): void
    {
        foreach ([
            ['evidence'   => "curl -H 'Authorization: Bearer ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ012345'"],
            ['summary'    => 'Fails unless DB_PASSWORD=hunter2xyz is exported first, which is the bug.'],
            ['workaround' => 'Hardcoded api_key: sk-abcdefghijklmnopqrstuvwxyz for now.'],
        ] as $overrides) {
            try {
                $this->valid($overrides);
                self::fail('Expected the secret scan to reject: ' . array_key_first($overrides));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('credential', $e->getMessage());
            }
        }
    }

    /**
     * Review finding on PR #40: $package is published verbatim by toMarkdown()
     * but was left out of the scan, leaving an unscanned path to a public tracker.
     */
    #[Test]
    public function the_package_field_is_scanned_for_credentials_too(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('credential');

        $this->valid(['package' => 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ012345']);
    }

    /**
     * Review finding on PR #40: tool output plausibly contains a run of three or
     * more backticks, which closed the fixed fence early and spilled the rest of
     * the evidence into the issue as loose Markdown.
     */
    #[Test]
    public function evidence_containing_backticks_stays_inside_its_fence(): void
    {
        $evidence = "Output was:\n```\nnested fence inside the evidence\n```\nand then it failed.";
        $markdown = $this->valid(['evidence' => $evidence])->toMarkdown();

        $fence = DefectReport::fenceFor($evidence);
        self::assertSame('````', $fence, 'a 3-backtick run needs a 4-backtick fence');
        self::assertStringContainsString($fence . "\n" . $evidence . "\n" . $fence, $markdown);
    }

    #[Test]
    public function the_fence_grows_with_the_longest_run(): void
    {
        self::assertSame('```', DefectReport::fenceFor('no backticks here'));
        self::assertSame('```', DefectReport::fenceFor('a `single` and a ``double``'));
        self::assertSame('````', DefectReport::fenceFor('a ``` triple'));
        self::assertSame('``````', DefectReport::fenceFor('a ````` five'));
    }

    #[Test]
    public function the_same_defect_from_two_projects_shares_a_fingerprint(): void
    {
        $a = $this->valid(['evidence' => 'run A produced the wrong band' . str_repeat('.', 20)]);
        $b = $this->valid(['evidence' => 'completely different repro text here' . str_repeat('.', 20)]);

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    /**
     * Review finding on PR #40, rated critical: the consent gate read
     * `!$yes && !$json`, so `--json` on its own satisfied "no prompt needed".
     * An agent adding it purely for parseable output — which is what every
     * other `ai:*` command trains it to do — published to a public tracker
     * unattended.
     */
    #[Test]
    public function json_alone_never_grants_consent_to_publish(): void
    {
        self::assertTrue(
            AiReportCommand::refusesUnattendedPublish(json: true, yes: false),
            '--json without --yes must be refused',
        );
        self::assertFalse(
            AiReportCommand::refusesUnattendedPublish(json: true, yes: true),
            '--json with an explicit --yes is consent',
        );
        self::assertFalse(
            AiReportCommand::refusesUnattendedPublish(json: false, yes: false),
            'interactive mode prompts rather than refusing',
        );
        self::assertFalse(
            AiReportCommand::refusesUnattendedPublish(json: false, yes: true),
            '--yes alone is consent',
        );
    }

    #[Test]
    public function search_terms_drop_noise_words(): void
    {
        $terms = $this->valid()->searchTerms();

        self::assertStringNotContainsString(' ai ', ' ' . $terms . ' ');
        self::assertStringContainsString('verify', $terms);
    }
}
