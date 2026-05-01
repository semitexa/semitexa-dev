<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceHeader;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Console\Command\AiTraceCommand;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end `ai:trace` sanity: chdir into a temp project root so the store
 * writes under `<tmp>/var/ai-traces/`, then exercise start/append/show/list
 * through CommandTester and assert the on-disk NDJSON plus the structured
 * responses.
 */
class AiTraceCommandTest extends TestCase
{
    private string $tmpRoot;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-ai-trace-cmd-' . uniqid();
        mkdir($this->tmpRoot . '/src/modules', 0755, true);
        file_put_contents($this->tmpRoot . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->tmpRoot);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        $this->removeDir($this->tmpRoot);
    }

    public function test_start_creates_trace_file_with_schema_header(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute([
            'action'   => 'start',
            '--id'     => 'ship-x',
            '--topic'  => 'Ship feature X',
            '--recipe' => 'add_authenticated_json_route',
        ]);

        $this->assertSame(0, $exit);
        $decoded = $this->decodeJson($tester->getDisplay());
        $header = $this->arrayValue($decoded, 'header');
        $this->assertSame('semitexa-dev.ai-trace-start/v1', $decoded['artifact']);
        $this->assertSame('created', $decoded['status']);
        $this->assertSame('ship-x', $header['trace_id']);
        $this->assertSame(TraceHeader::SCHEMA_VERSION, $header['schema_version']);
        $this->assertSame('var/ai-traces/ship-x.ndjson', $decoded['path']);
        $this->assertFileExists($this->tmpRoot . '/var/ai-traces/ship-x.ndjson');
    }

    public function test_append_emits_event_with_monotonic_id_and_known_kind(): void
    {
        $this->start('t');
        $tester = $this->newTester();
        $exit = $tester->execute([
            'action'    => 'append',
            '--id'      => 't',
            '--kind'    => TraceEventKind::VERIFY_RESULT,
            '--summary' => 'all lints pass',
            '--payload' => json_encode(['verdict' => 'pass', 'counts' => ['pass' => 2, 'fail' => 0]]),
        ]);

        $this->assertSame(0, $exit);
        $decoded = $this->decodeJson($tester->getDisplay());
        $event = $this->arrayValue($decoded, 'event');
        $payload = $this->arrayValue($event, 'payload');
        $this->assertSame(1, $event['event_id']);
        $this->assertSame(TraceEventKind::VERIFY_RESULT, $event['event_kind']);
        $this->assertSame('pass', $payload['verdict']);
    }

    public function test_show_returns_full_trace_in_order(): void
    {
        $this->start('t');
        $this->append('t', TraceEventKind::TASK_RESULT, 'first');
        $this->append('t', TraceEventKind::PLAN_DECISION, 'second');
        $this->append('t', TraceEventKind::VERIFY_RESULT, 'third');

        $tester = $this->newTester();
        $exit = $tester->execute(['action' => 'show', '--id' => 't', '--json' => true]);

        $this->assertSame(0, $exit);
        $decoded = $this->decodeJson($tester->getDisplay());
        $events = $this->listValue($decoded, 'events');
        $this->assertSame('semitexa-dev.ai-trace-report/v1', $decoded['artifact']);
        $this->assertSame(3, $decoded['event_count']);
        $this->assertSame(['first', 'second', 'third'], array_column($events, 'summary'));
        $this->assertSame([1, 2, 3], array_column($events, 'event_id'));
    }

    public function test_show_ndjson_default_emits_summary_header_and_events(): void
    {
        $this->start('t');
        $this->append('t', TraceEventKind::NOTE, 'hello');

        $tester = $this->newTester();
        $tester->execute(['action' => 'show', '--id' => 't']);

        $lines = $this->ndjsonLines($tester->getDisplay());
        $kinds = array_map(static fn($l) => $l['kind'] ?? null, $lines);
        $this->assertSame(['summary', 'header', 'event'], $kinds);
        $this->assertSame('hello', $lines[2]['summary']);
    }

    public function test_list_enumerates_all_traces(): void
    {
        $this->start('alpha');
        $this->start('beta');

        $tester = $this->newTester();
        $tester->execute(['action' => 'list', '--json' => true]);

        $decoded = $this->decodeJson($tester->getDisplay());
        $traces = $this->listValue($decoded, 'traces');
        $ids = array_column($traces, 'trace_id');
        sort($ids);
        $this->assertSame(['alpha', 'beta'], $ids);
        $this->assertSame(2, $decoded['trace_count']);
    }

    public function test_append_on_missing_trace_errors(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute([
            'action'    => 'append',
            '--id'      => 'not-started',
            '--kind'    => TraceEventKind::NOTE,
            '--summary' => 'anything',
        ]);

        $this->assertSame(1, $exit);
        $decoded = $this->decodeJson($tester->getDisplay());
        $this->assertSame('error', $decoded['kind']);
        $this->assertStringContainsString('does not exist', $this->stringValue($decoded, 'error'));
    }

    public function test_invalid_trace_id_rejected_with_error_envelope(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['action' => 'start', '--id' => 'Bad Id']);
        $this->assertSame(1, $exit);
        $decoded = $this->decodeJson($tester->getDisplay());
        $this->assertStringContainsString('invalid trace id', $this->stringValue($decoded, 'error'));
    }

    public function test_unknown_event_kind_rejected(): void
    {
        $this->start('t');
        $tester = $this->newTester();
        $exit = $tester->execute([
            'action'    => 'append',
            '--id'      => 't',
            '--kind'    => 'gibberish_event',
            '--summary' => 'x',
        ]);
        $this->assertSame(1, $exit);
        $decoded = $this->decodeJson($tester->getDisplay());
        $this->assertStringContainsString('unknown event kind', $this->stringValue($decoded, 'error'));
    }

    public function test_bad_payload_json_rejected(): void
    {
        $this->start('t');
        $tester = $this->newTester();
        $exit = $tester->execute([
            'action'    => 'append',
            '--id'      => 't',
            '--kind'    => TraceEventKind::NOTE,
            '--summary' => 'x',
            '--payload' => '{not valid json',
        ]);
        $this->assertSame(1, $exit);
        $decoded = $this->decodeJson($tester->getDisplay());
        $this->assertStringContainsString('invalid --payload JSON', $this->stringValue($decoded, 'error'));
    }

    public function test_start_is_idempotent(): void
    {
        $this->start('t', 'Original');
        $tester = $this->newTester();
        $tester->execute(['action' => 'start', '--id' => 't', '--topic' => 'Rewrite attempt']);
        $decoded = $this->decodeJson($tester->getDisplay());
        $this->assertSame('opened', $decoded['status']);

        // Show: topic must still be 'Original'.
        $show = $this->newTester();
        $show->execute(['action' => 'show', '--id' => 't', '--json' => true]);
        $decoded = $this->decodeJson($show->getDisplay());
        $this->assertSame('Original', $this->arrayValue($decoded, 'header')['topic']);
    }

    public function test_missing_id_on_actions_returns_error(): void
    {
        $tester = $this->newTester();
        foreach (['start', 'append', 'show'] as $action) {
            $tester->execute(['action' => $action]);
            $decoded = $this->decodeJson($tester->getDisplay());
            $this->assertSame('error', $decoded['kind'], "missing id for {$action}");
        }
    }

    public function test_unknown_action_rejected(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['action' => 'delete']);
        $this->assertSame(1, $exit);
        $decoded = $this->decodeJson($tester->getDisplay());
        $this->assertStringContainsString("unknown action", $this->stringValue($decoded, 'error'));
    }

    public function test_on_disk_format_is_valid_ndjson(): void
    {
        $this->start('t');
        $this->append('t', TraceEventKind::TASK_RESULT, 'a');
        $this->append('t', TraceEventKind::VERIFY_RESULT, 'b');

        $raw = file_get_contents($this->tmpRoot . '/var/ai-traces/t.ndjson');
        $lines = array_values(array_filter(explode("\n", (string) $raw)));
        $this->assertCount(3, $lines);

        $header = $this->decodeJson($lines[0]);
        $this->assertSame('header', $header['kind']);
        $this->assertSame(TraceHeader::SCHEMA_VERSION, $header['schema_version']);

        foreach ([1, 2] as $idx) {
            $row = $this->decodeJson($lines[$idx]);
            $this->assertSame('event', $row['kind']);
            $this->assertSame($idx, $row['event_id']);
        }
    }

    private function newTester(): CommandTester
    {
        $command = new AiTraceCommand();
        PropertyInjector::inject($command, new ArrayContainer([
            TraceStore::class => new TraceStore(),
        ]));
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('ai:trace'));
    }

    private function start(string $id, string $topic = 'topic'): void
    {
        $this->newTester()->execute([
            'action'  => 'start',
            '--id'    => $id,
            '--topic' => $topic,
        ]);
    }

    private function append(string $id, string $kind, string $summary): void
    {
        $this->newTester()->execute([
            'action'    => 'append',
            '--id'      => $id,
            '--kind'    => $kind,
            '--summary' => $summary,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ndjsonLines(string $output): array
    {
        $lines = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $lines[] = $this->decodeJson($line);
        }
        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function arrayValue(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        $this->assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function listValue(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        $this->assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
