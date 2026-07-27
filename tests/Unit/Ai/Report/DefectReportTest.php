<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

    #[Test]
    public function the_same_defect_from_two_projects_shares_a_fingerprint(): void
    {
        $a = $this->valid(['evidence' => 'run A produced the wrong band' . str_repeat('.', 20)]);
        $b = $this->valid(['evidence' => 'completely different repro text here' . str_repeat('.', 20)]);

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    #[Test]
    public function search_terms_drop_noise_words(): void
    {
        $terms = $this->valid()->searchTerms();

        self::assertStringNotContainsString(' ai ', ' ' . $terms . ' ');
        self::assertStringContainsString('verify', $terms);
    }
}
