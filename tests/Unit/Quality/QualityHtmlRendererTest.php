<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Quality\QualityHtmlRenderer;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;

final class QualityHtmlRendererTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-quality-page-' . uniqid();
        mkdir(dirname($this->root . '/' . QualityLedger::BASELINE), 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/' . QualityLedger::BASELINE);
        @unlink($this->root . '/' . QualityLedger::HISTORY);
        foreach (['packages/semitexa-dev/resources/quality', 'packages/semitexa-dev/resources', 'packages/semitexa-dev', 'packages', ''] as $d) {
            @rmdir(rtrim($this->root . '/' . $d, '/'));
        }
    }

    #[Test]
    public function a_metric_shows_its_trend_its_blind_spot_and_its_raises(): void
    {
        file_put_contents($this->root . '/' . QualityLedger::BASELINE, json_encode([
            'metrics' => ['requests.duplicate-queries' => [
                'total' => 2, 'breakdown' => ['GET /<script>' => 2],
                'sees' => 'repeated statements', 'blind' => 'N+1 over distinct ids',
            ]],
            'deliberate' => [['at' => '2026-09-24', 'metric' => 'requests.duplicate-queries', 'from' => 1, 'to' => 2, 'reason' => 'the importer needs it']],
        ]));
        file_put_contents($this->root . '/' . QualityLedger::HISTORY, implode("\n", [
            '{"at":"2026-09-20T00:00:00+00:00","metric":"requests.duplicate-queries","total":5,"event":"new"}',
            '{"at":"2026-09-24T00:00:00+00:00","metric":"requests.duplicate-queries","total":2,"event":"record"}',
        ]) . "\n");

        $html = (new QualityHtmlRenderer())->render($this->root);

        self::assertStringContainsString('<svg', $html, 'two readings draw a trend');
        self::assertStringContainsString('-3 since 2026-09-20', $html);
        self::assertStringContainsString('N+1 over distinct ids', $html, 'what the metric cannot see is on the page');
        self::assertStringContainsString('the importer needs it', $html, 'a raise shows its reason');
        self::assertStringContainsString('GET /&lt;script&gt;', $html);
        self::assertStringNotContainsString('GET /<script>', $html);
    }

    #[Test]
    public function without_a_ledger_it_says_how_to_start_one(): void
    {
        self::assertStringContainsString('ai:quality record', (new QualityHtmlRenderer())->render($this->root));
    }
}
