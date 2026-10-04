<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\RuleStats;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleFireLedger;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleFireReport;
use Semitexa\Dev\Application\Service\Ai\Verify\RuleStats\RuleFires;

final class RuleFiresTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function envelope(string $at = '2026-10-04T10:00:00+00:00'): array
    {
        return [
            'generated_at' => $at,
            'results' => [
                ['id' => 'phpstan_di:2-files', 'type' => 'phpstan_di', 'status' => 'fail', 'signal' => '', 'accepted' => [['identifier' => 'semitexa.builtSqlFragment']]],
                ['id' => 'module_structure:packages/semitexa-x', 'type' => 'module_structure', 'status' => 'pass', 'signal' => ''],
                ['id' => 'lint:lint:di', 'type' => 'lint', 'status' => 'fail', 'signal' => '1 error.'],
                ['id' => 'lint:test-integrity', 'type' => 'lint', 'status' => 'pass', 'signal' => ''],
                ['id' => 'phpunit:packages/semitexa-dev/tests/Unit/Structure', 'type' => 'phpunit', 'status' => 'fail', 'signal' => 'StructuralOutlierBudgetTest::no_recorded_outlier_has_grown — grew · '],
                ['id' => 'live_tenancy:project', 'type' => 'live_tenancy', 'status' => 'skipped', 'signal' => ''],
                ['id' => 'syntax:a.php', 'type' => 'syntax', 'status' => 'pass', 'signal' => ''],
            ],
            'violations' => [
                ['check' => 'phpstan_di', 'rule' => 'semitexa.disallowErrorLog', 'identifier' => 'semitexa.disallowErrorLog'],
                ['check' => 'lint', 'rule' => 'lint:di'],
            ],
        ];
    }

    #[Test]
    public function a_run_names_the_families_that_had_a_chance_and_the_rules_that_fired(): void
    {
        self::assertSame([
            'at'      => '2026-10-04T10:00:00+00:00',
            // skipped live_tenancy had no chance; syntax is not a rule family
            'chances' => ['phpstan', 'module_structure', 'lint:di', 'lint:test-integrity', 'ratchets'],
            // the accepted hit fired too; lint:di is counted once
            'fired'   => ['semitexa.builtSqlFragment', 'lint:di', 'StructuralOutlierBudgetTest', 'semitexa.disallowErrorLog'],
        ], RuleFires::fromEnvelope(self::envelope()));
        self::assertNull(RuleFires::fromEnvelope(['verdict' => 'pass']), 'an old summary-only event is not a run record');
    }

    #[Test]
    public function the_ledger_keeps_every_run_and_a_traced_run_is_counted_once(): void
    {
        $root = sys_get_temp_dir() . '/rule-fires-' . bin2hex(random_bytes(4));
        mkdir($root . '/var/ai-traces', 0777, true);
        try {
            $ledger = new RuleFireLedger($root);
            $ledger->record(self::envelope('2026-10-04T10:00:00+00:00'));
            file_put_contents($root . '/var/ai-traces/t.ndjson', implode("\n", [
                json_encode(['kind' => 'event', 'event_kind' => 'verify_result', 'payload' => self::envelope('2026-10-04T10:00:00+00:00')]),
                json_encode(['kind' => 'event', 'event_kind' => 'verify_result', 'payload' => self::envelope('2026-04-19T08:00:00+00:00')]),
                json_encode(['kind' => 'event', 'event_kind' => 'note', 'payload' => []]),
            ]) . "\n");

            self::assertSame(['2026-04-19T08:00:00+00:00', '2026-10-04T10:00:00+00:00'], array_column($ledger->runs(), 'at'));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        // Recording where nothing can be written must not fail the verify run.
        (new RuleFireLedger('/proc/no-such-root'))->record(self::envelope());
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_report_puts_dormant_rules_first_and_names_retired_ones(): void
    {
        $runs = [RuleFires::fromEnvelope(self::envelope()), RuleFires::fromEnvelope(self::envelope('2026-10-05T10:00:00+00:00'))];
        $report = RuleFireReport::build([
            'semitexa.disallowErrorLog' => 'phpstan',
            'semitexa.traitInjection'   => 'phpstan',
            'live_scope_unbacked'       => 'live_tenancy',
            'lint:di'                   => 'lint:di',
        ], array_values(array_filter($runs)));

        self::assertSame([
            ['semitexa.traitInjection', 2, 0],
            ['live_scope_unbacked', 0, 0],
            ['lint:di', 2, 2],
            ['semitexa.disallowErrorLog', 2, 2],
        ], array_map(static fn (array $r): array => [$r['rule'], $r['chances'], $r['fires']], $report['rules']));
        self::assertSame(['StructuralOutlierBudgetTest' => 2, 'semitexa.builtSqlFragment' => 2], $report['retired']);
    }

    #[Test]
    public function trimming_keeps_the_newest_runs_and_the_one_just_recorded(): void
    {
        $root = sys_get_temp_dir() . '/rule-fires-trim-' . bin2hex(random_bytes(4));
        mkdir($root . '/var/run', 0777, true);
        $line = json_encode(['at' => 'old', 'chances' => [str_repeat('x', 420)], 'fired' => []]);
        file_put_contents($root . '/' . RuleFireLedger::FILE, str_repeat($line . "\n", 5001));
        try {
            (new RuleFireLedger($root))->record(self::envelope('2026-10-04T12:00:00+00:00'));
            $lines = file($root . '/' . RuleFireLedger::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            self::assertCount(5000, $lines);
            self::assertStringContainsString('"at":"2026-10-04T12:00:00+00:00"', (string) end($lines));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
