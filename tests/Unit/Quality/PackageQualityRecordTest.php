<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;
use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\PackageQualityRecord;
use Semitexa\Dev\Application\Service\Quality\QualityHtmlRenderer;
use Semitexa\Dev\Application\Service\Quality\QualityLedger;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;
use Semitexa\Dev\Application\Service\Quality\Verdict;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * A package's rise is accepted into the package's own repository
 * (tk-quality-record-lives-with-the-package). Accepted into semitexa-dev, it
 * needed a commit there too; on 2026-11-11 that commit had nowhere to go and
 * the release preflight failed on `workspace-is-pushed`.
 */
final class PackageQualityRecordTest extends TestCase
{
    private string $root;

    /** @var array<string, int> what the fake metric reports next */
    private array $reading = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-pkg-quality-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages/semitexa-os', 0o755, true);
        mkdir($this->root . '/packages/semitexa-dev', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_package_rise_is_accepted_into_the_package_and_leaves_the_central_file_alone(): void
    {
        $this->reading = ['semitexa-os' => 1, 'GET /x' => 2];
        $this->ledger()->record();
        $central = (string) file_get_contents($this->root . '/' . QualityLedger::BASELINE);
        $history = (string) file_get_contents($this->root . '/' . QualityLedger::HISTORY);

        $this->reading = ['semitexa-os' => 2, 'GET /x' => 2];
        self::assertSame(Verdict::WORSE, $this->ledger()->check()[0]->status);
        $this->ledger()->accept('fake.metric', 'the os test skips its MySQL half without MySQL');

        self::assertSame($central, (string) file_get_contents($this->root . '/' . QualityLedger::BASELINE), 'semitexa-dev is not touched');
        self::assertSame($history, (string) file_get_contents($this->root . '/' . QualityLedger::HISTORY));
        $record = json_decode((string) file_get_contents(PackageQualityRecord::path($this->root, 'semitexa-os')), true);
        self::assertSame(2, $record['metrics']['fake.metric']['count']);
        self::assertSame('the os test skips its MySQL half without MySQL', $record['metrics']['fake.metric']['accepted'][0]['reason']);
        self::assertSame(Verdict::SAME, $this->ledger()->check()[0]->status, 'the record now passes the gate');
        self::assertSame(4, $this->ledger()->baseline()['fake.metric']['total'], 'counted once: the record replaces the central value');
    }

    #[Test]
    public function a_key_no_package_owns_still_goes_to_the_central_file(): void
    {
        $this->reading = ['semitexa-os' => 1, 'GET /x' => 2];
        $this->ledger()->record();

        $this->reading = ['semitexa-os' => 1, 'GET /x' => 3];
        $this->ledger()->accept('fake.metric', 'the route renders one more query on purpose');

        $central = json_decode((string) file_get_contents($this->root . '/' . QualityLedger::BASELINE), true);
        self::assertSame(['GET /x' => 3, 'semitexa-os' => 1], $central['metrics']['fake.metric']['breakdown']);
        self::assertFileDoesNotExist(PackageQualityRecord::path($this->root, 'semitexa-os'));
    }

    #[Test]
    public function a_package_without_a_record_falls_back_and_an_improvement_lowers_its_record(): void
    {
        $this->reading = ['semitexa-os' => 3];
        $this->ledger()->record();
        PackageQualityRecord::write($this->root, 'semitexa-os', 'fake.metric', 3, null);

        $this->reading = ['semitexa-os' => 1];
        $this->ledger()->record();

        $record = json_decode((string) file_get_contents(PackageQualityRecord::path($this->root, 'semitexa-os')), true);
        self::assertSame(1, $record['metrics']['fake.metric']['count'], 'a lower count is locked into the record too');
        $this->reading = ['semitexa-os' => 2];
        self::assertSame(Verdict::WORSE, $this->ledger()->check()[0]->status, 'and climbing back is a rise again');
    }

    #[Test]
    public function an_unreadable_record_is_an_error_not_a_fallback(): void
    {
        $this->putRecord('{not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a readable package quality record');
        PackageQualityRecord::overlay($this->root, ['fake.metric' => ['total' => 1, 'breakdown' => ['semitexa-os' => 1]]]);
    }

    #[Test]
    public function a_record_without_metrics_is_an_error(): void
    {
        foreach (['{}', '{"metrics":null}'] as $json) {
            $this->putRecord($json);
            try {
                PackageQualityRecord::all($this->root);
                self::fail("{$json} read as a record");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('is not a readable package quality record', $e->getMessage());
            }
        }
    }

    /** A metric new to the central file takes its reading into an existing record too (review, dev#235). */
    #[Test]
    public function a_metric_new_to_the_central_file_reconciles_an_existing_record(): void
    {
        foreach (['record', 'accept'] as $how) {
            exec('rm -rf ' . escapeshellarg($this->root . '/packages/semitexa-dev/resources'));
            PackageQualityRecord::write($this->root, 'semitexa-os', 'fake.metric', 1, null);
            $this->reading = ['semitexa-os' => 2];
            self::assertSame(Verdict::NEW, $this->ledger()->check()[0]->status);

            $how === 'record' ? $this->ledger()->record() : $this->ledger()->accept('fake.metric', 'first reading, with a record from before');

            self::assertSame(Verdict::SAME, $this->ledger()->check()[0]->status, "{$how}: the next check passes the same reading");
        }
    }

    #[Test]
    public function the_report_lists_a_package_acceptance_with_its_reason(): void
    {
        $this->reading = ['semitexa-os' => 1];
        $this->ledger()->record();
        $this->reading = ['semitexa-os' => 2];
        $this->ledger()->accept('fake.metric', 'the os test skips its MySQL half without MySQL');

        $html = (new QualityHtmlRenderer())->render($this->root);

        self::assertStringContainsString('fake.metric (semitexa-os)', $html);
        self::assertStringContainsString('the os test skips its MySQL half without MySQL', $html);
    }

    #[Test]
    public function a_broken_record_shows_in_the_report_instead_of_crashing_it(): void
    {
        $this->reading = ['semitexa-os' => 1];
        $this->ledger()->record();
        $this->putRecord('{not json');

        self::assertStringContainsString('is not a readable package quality record', (new QualityHtmlRenderer())->render($this->root));
    }

    /** The record sits where a package may hold it: at the root, module_structure refused it (semitexa-crud#12). */
    #[Test]
    public function a_package_holding_its_record_still_passes_module_structure(): void
    {
        $package = $this->root . '/packages/semitexa-os';
        file_put_contents($package . '/composer.json', '{"name": "semitexa/os"}');
        PackageQualityRecord::write($this->root, 'semitexa-os', 'fake.metric', 1, null);

        $violations = (new ModuleStructureValidator(
            $this->root,
            (new ModuleStructureSpecLoader(dirname(__DIR__, 6)))->load(),
        ))->validate(new DetectedModule(name: 'os', relativePath: 'packages/semitexa-os', kind: DetectedModule::KIND_PACKAGE));

        $codes = array_map(static fn ($v): string => $v->code . ' ' . $v->path, $violations);
        // `resources` too: a spec that refused the directory would name it, not the file (review, dev#237).
        self::assertSame([], array_values(array_filter($codes, static fn (string $c): bool => str_contains($c, 'quality') || str_contains($c, 'resources'))), implode("\n", $codes));
        self::assertFileExists($package . '/resources/quality.json');
    }

    #[Test]
    public function a_record_left_at_the_package_root_is_an_error_not_ignored(): void
    {
        file_put_contents($this->root . '/packages/semitexa-os/quality.json', '{"metrics": {"fake.metric": {"count": 3}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('move it to');
        PackageQualityRecord::all($this->root);
    }

    private function putRecord(string $json): void
    {
        $path = PackageQualityRecord::path($this->root, 'semitexa-os');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, $json);
    }

    private function ledger(): QualityLedger
    {
        $metric = new class ($this) implements QualityMetricInterface {
            public function __construct(private readonly PackageQualityRecordTest $test)
            {
            }

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
