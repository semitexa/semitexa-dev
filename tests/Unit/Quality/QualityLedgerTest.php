<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;
use Semitexa\Dev\Application\Service\Quality\Verdict;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * The ledger's rules, which are the whole point of it: record() never raises,
 * accept() is the only way up and needs a reason, an improvement fails until it
 * is recorded, and a regression in one key cannot hide behind a fix in another.
 */
final class QualityLedgerTest extends TestCase
{
    private string $root;

    /** @var array<string, int> what the fake metric reports next */
    private array $reading = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-quality-' . uniqid();
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ([QualityLedger::BASELINE, QualityLedger::HISTORY] as $f) {
            @unlink($this->root . '/' . $f);
        }
        @rmdir($this->root . '/packages/semitexa-dev/resources/quality');
        @rmdir($this->root . '/packages/semitexa-dev/resources');
        @rmdir($this->root . '/packages/semitexa-dev');
        @rmdir($this->root . '/packages');
        @rmdir($this->root);
    }

    #[Test]
    public function a_new_metric_is_not_a_pass_until_it_is_recorded(): void
    {
        $this->reading = ['a' => 2];
        self::assertSame(Verdict::NEW, $this->ledger()->check()[0]->status);

        $this->ledger()->record();

        self::assertSame(Verdict::SAME, $this->ledger()->check()[0]->status);
        // The trend starts at the first reading, not the second.
        self::assertStringContainsString('"event":"new"', (string) file_get_contents($this->root . '/' . QualityLedger::HISTORY));
    }

    #[Test]
    public function a_rise_in_one_key_is_worse_even_when_the_total_holds(): void
    {
        $this->reading = ['a' => 2, 'b' => 2];
        $this->ledger()->record();

        $this->reading = ['a' => 1, 'b' => 3];

        $verdict = $this->ledger()->check()[0];
        self::assertSame(Verdict::WORSE, $verdict->status);
        self::assertSame(['a' => ['from' => 2, 'to' => 1], 'b' => ['from' => 2, 'to' => 3]], $verdict->moved);
    }

    #[Test]
    public function an_improvement_fails_until_it_is_locked_in(): void
    {
        $this->reading = ['a' => 2, 'b' => 1];
        $this->ledger()->record();
        $this->reading = ['a' => 2];

        self::assertSame(Verdict::BETTER, $this->ledger()->check()[0]->status);
        self::assertFalse($this->ledger()->check()[0]->passes());

        $this->ledger()->record();

        self::assertSame(Verdict::SAME, $this->ledger()->check()[0]->status);
    }

    #[Test]
    public function record_never_raises_a_metric(): void
    {
        $this->reading = ['a' => 1];
        $this->ledger()->record();
        $before = (string) file_get_contents($this->root . '/' . QualityLedger::BASELINE);
        $this->reading = ['a' => 5];

        $result = $this->ledger()->record();

        self::assertCount(1, $result['refused']);
        self::assertSame($before, file_get_contents($this->root . '/' . QualityLedger::BASELINE));
    }

    #[Test]
    public function accept_raises_only_with_a_reason_and_writes_it_down(): void
    {
        $this->reading = ['a' => 1];
        $this->ledger()->record();
        $this->reading = ['a' => 3];

        try {
            $this->ledger()->accept('fake.metric', 'because');
            self::fail('a placeholder reason must be refused');
        } catch (\InvalidArgumentException) {
        }

        $this->ledger()->accept('fake.metric', 'the new importer needs two more of these, see #42');

        $data = json_decode((string) file_get_contents($this->root . '/' . QualityLedger::BASELINE), true);
        self::assertSame(3, $data['metrics']['fake.metric']['total']);
        self::assertSame('the new importer needs two more of these, see #42', $data['deliberate'][0]['reason']);
        self::assertSame(['from' => 1, 'to' => 3], ['from' => $data['deliberate'][0]['from'], 'to' => $data['deliberate'][0]['to']]);
        self::assertStringContainsString('"event":"accept"', (string) file_get_contents($this->root . '/' . QualityLedger::HISTORY));
    }

    #[Test]
    public function an_unreadable_ledger_is_an_error_not_an_empty_one(): void
    {
        // Read as empty, every metric would be NEW and record() would rewrite
        // the history with whatever the tree says today.
        mkdir(dirname($this->root . '/' . QualityLedger::BASELINE), 0o755, true);
        file_put_contents($this->root . '/' . QualityLedger::BASELINE, '{"metrics": oops');
        $this->reading = ['a' => 1];

        $this->expectException(\RuntimeException::class);
        $this->ledger()->record();
    }

    private function ledger(): QualityLedger
    {
        $test = $this;
        $metric = new class ($test) implements QualityMetricInterface {
            public function __construct(private readonly QualityLedgerTest $test) {}

            public function measure(string $projectRoot): Measurement
            {
                return new Measurement($this->test->reading());
            }
        };

        return new QualityLedger($this->root, [
            'fake.metric' => ['metric' => $metric, 'meta' => new AsQualityMetric(id: 'fake.metric', sees: 's', blind: 'b')],
        ]);
    }

    /** @return array<string, int> */
    public function reading(): array
    {
        return $this->reading;
    }
}
