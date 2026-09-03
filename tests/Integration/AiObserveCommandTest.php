<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Dev\Application\Console\Command\AiObserveCommand;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;
use Semitexa\Dev\Application\Service\Trace\ReplayRunner;
use Semitexa\Dev\Application\Service\Trace\SourceSliceReader;
use Semitexa\Dev\Application\Service\Trace\TraceReader;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Semitexa\Testing\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `ai:observe` end-to-end over a seeded journal: ps folds live/recent, show
 * resolves one process and inlines its trace, and every answer is one JSON
 * envelope an agent can parse without scraping.
 */
final class AiObserveCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/semitexa-ai-observe-' . uniqid();
        mkdir($this->dir . '/observatory', 0755, true);
        mkdir($this->dir . '/trace', 0755, true);
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->dir . '/observatory');
        putenv('SEMITEXA_TRACE_DIR=' . $this->dir . '/trace');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('SEMITEXA_OBSERVATORY_DIR');
        putenv('SEMITEXA_TRACE_DIR');
        putenv('SEMITEXA_OBSERVATORY_MODE');
        foreach (['/observatory', '/trace'] as $sub) {
            foreach (glob($this->dir . $sub . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir . $sub);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function seedJournal(array $rows): void
    {
        file_put_contents(
            $this->dir . '/observatory/journal-' . date('Ymd') . '.ndjson',
            implode("\n", array_map(static fn (array $r): string => (string) json_encode($r), $rows)) . "\n",
        );
    }

    private function tester(): CommandTester
    {
        $command = new AiObserveCommand();
        PropertyInjector::inject($command, new ArrayContainer([
            ObservatoryReader::class => new ObservatoryReader(),
            TraceReader::class => new TraceReader(),
            // Bare instance: these tests never reach the replay action; its
            // own dependencies are exercised by ReplayGuardsTest and live runs.
            ReplayRunner::class => new ReplayRunner(),
            SourceSliceReader::class => new SourceSliceReader(),
        ]));
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('ai:observe'));
    }

    #[Test]
    public function ps_answers_one_envelope_with_live_and_recent(): void
    {
        $this->seedJournal([
            ['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-live', 'kind' => 'http', 'name' => 'InFlight', 'worker' => 1],
            ['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-done', 'kind' => 'http', 'name' => 'Finished', 'worker' => 1],
            ['ts' => date('c'), 'event' => 'end', 'id' => 'p-1-done', 'kind' => 'http', 'name' => 'Finished', 'worker' => 1, 'durationMs' => 7.5],
        ]);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['action' => 'ps']));

        $envelope = json_decode($tester->getDisplay(), true);
        self::assertSame('semitexa-dev.ai-observe.ps/v1', $envelope['artifact']);
        self::assertSame(1, $envelope['counts']['live']);
        self::assertSame('InFlight', $envelope['live'][0]['name']);
        self::assertSame('Finished', $envelope['recent'][0]['name']);
        self::assertNotEmpty($envelope['next_command'], 'the agent is told where to go next');
    }

    #[Test]
    public function tail_emits_raw_ndjson_rows_filtered_by_kind(): void
    {
        $this->seedJournal([
            ['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-a', 'kind' => 'http', 'name' => 'A', 'worker' => 1],
            ['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-b', 'kind' => 'sse', 'name' => 'B', 'worker' => 1],
        ]);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['action' => 'tail', '--kind' => 'sse']));

        $lines = array_values(array_filter(explode("\n", trim($tester->getDisplay()))));
        self::assertCount(1, $lines, 'only the sse row passes the filter');
        self::assertSame('B', json_decode($lines[0], true)['name']);
    }

    #[Test]
    public function show_inlines_the_trace_when_the_process_was_recorded(): void
    {
        file_put_contents($this->dir . '/trace/t1.json', (string) json_encode([
            'recordedAt' => date('c'),
            'truncated' => false,
            'totalMs' => 12.0,
            'events' => [
                ['type' => 'begin', 'name' => 'request', 'depth' => 0, 'atMs' => 0.0, 'cid' => 1, 'pcid' => 0, 'context' => ['method' => 'GET', 'path' => '/x']],
                ['type' => 'end', 'name' => 'request', 'depth' => 0, 'atMs' => 12.0, 'cid' => 1, 'pcid' => 0, 'context' => [], 'durationMs' => 12.0],
            ],
        ]));
        $this->seedJournal([
            ['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-t', 'kind' => 'http', 'name' => 'Traced', 'worker' => 1],
            ['ts' => date('c'), 'event' => 'end', 'id' => 'p-1-t', 'kind' => 'http', 'name' => 'Traced', 'worker' => 1, 'durationMs' => 12.0, 'trace' => 't1.json'],
        ]);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['action' => 'show', '--id' => 'p-1-t']));

        $envelope = json_decode($tester->getDisplay(), true);
        self::assertSame('done', $envelope['status']);
        self::assertSame('t1.json', $envelope['end']['trace']);
        self::assertArrayHasKey('trace', $envelope, 'the recorded trace is inlined, bytes on demand');
        self::assertNotEmpty($envelope['trace']['spans'] ?? [], 'spans arrive resolved, not as raw events');
    }

    #[Test]
    public function show_with_source_inlines_the_code_each_span_ran(): void
    {
        // Two spans onto the same handler: one slice, two refs.
        $handler = 'Semitexa\\Dev\\Tests\\Fixtures\\Source\\SlicedFixture';
        file_put_contents($this->dir . '/trace/t2.json', (string) json_encode([
            'recordedAt' => date('c'),
            'truncated' => false,
            'totalMs' => 12.0,
            'events' => [
                ['type' => 'begin', 'name' => 'request', 'depth' => 0, 'atMs' => 0.0, 'cid' => 1, 'pcid' => 0, 'context' => ['method' => 'GET', 'path' => '/x']],
                ['type' => 'begin', 'name' => 'pipeline', 'depth' => 1, 'atMs' => 1.0, 'cid' => 1, 'pcid' => 0, 'context' => []],
                ['type' => 'end', 'name' => 'pipeline', 'depth' => 1, 'atMs' => 5.0, 'cid' => 1, 'pcid' => 0, 'context' => ['handler' => $handler, 'method' => 'target'], 'durationMs' => 4.0],
                ['type' => 'begin', 'name' => 'pipeline', 'depth' => 1, 'atMs' => 6.0, 'cid' => 1, 'pcid' => 0, 'context' => []],
                ['type' => 'end', 'name' => 'pipeline', 'depth' => 1, 'atMs' => 9.0, 'cid' => 1, 'pcid' => 0, 'context' => ['handler' => $handler, 'method' => 'target'], 'durationMs' => 3.0],
                ['type' => 'end', 'name' => 'request', 'depth' => 0, 'atMs' => 12.0, 'cid' => 1, 'pcid' => 0, 'context' => [], 'durationMs' => 12.0],
            ],
        ]));
        $this->seedJournal([
            ['ts' => date('c'), 'event' => 'begin', 'id' => 'p-2-t', 'kind' => 'http', 'name' => 'Traced', 'worker' => 1],
            ['ts' => date('c'), 'event' => 'end', 'id' => 'p-2-t', 'kind' => 'http', 'name' => 'Traced', 'worker' => 1, 'durationMs' => 12.0, 'trace' => 't2.json'],
        ]);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['action' => 'show', '--id' => 'p-2-t', '--source' => true]));

        $envelope = json_decode($tester->getDisplay(), true);
        $refs = array_values(array_filter(array_column($envelope['trace']['spans'], 'source_ref')));
        self::assertSame([$handler . '::target', $handler . '::target'], $refs, 'every span that named a class carries a ref');
        self::assertCount(1, $envelope['source'], 'the same Class::method is read once, however often it ran');
        $slice = $envelope['source'][$handler . '::target'];
        self::assertSame('target', $slice['method']);
        self::assertStringEndsWith('tests/Fixtures/Source/SlicedFixture.php', $slice['file']);
        self::assertStringContainsString('FIXTURE_TARGET_BODY', implode("\n", $slice['lines']));
        self::assertArrayNotHasKey('source', $envelope['trace'], 'the map lives on the envelope, not inside the trace');
    }

    #[Test]
    public function without_the_flag_show_adds_no_source(): void
    {
        file_put_contents($this->dir . '/trace/t3.json', (string) json_encode([
            'recordedAt' => date('c'), 'truncated' => false, 'totalMs' => 1.0,
            'events' => [
                ['type' => 'begin', 'name' => 'request', 'depth' => 0, 'atMs' => 0.0, 'cid' => 1, 'pcid' => 0, 'context' => ['method' => 'GET', 'path' => '/x']],
                ['type' => 'end', 'name' => 'request', 'depth' => 0, 'atMs' => 1.0, 'cid' => 1, 'pcid' => 0, 'context' => [], 'durationMs' => 1.0],
            ],
        ]));
        $this->seedJournal([
            ['ts' => date('c'), 'event' => 'end', 'id' => 'p-3-t', 'kind' => 'http', 'name' => 'Traced', 'worker' => 1, 'durationMs' => 1.0, 'trace' => 't3.json'],
        ]);

        $tester = $this->tester();
        $tester->execute(['action' => 'show', '--id' => 'p-3-t']);

        $envelope = json_decode($tester->getDisplay(), true);
        self::assertArrayNotHasKey('source', $envelope);
        self::assertArrayNotHasKey('source_ref', $envelope['trace']['spans'][0]);
    }

    #[Test]
    public function show_refuses_an_unknown_id_with_a_hint(): void
    {
        $this->seedJournal([]);

        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['action' => 'show', '--id' => 'p-9-nope']));

        $envelope = json_decode($tester->getDisplay(), true);
        self::assertSame('unknown-process', $envelope['error']);
    }

    #[Test]
    public function outside_dev_the_command_refuses(): void
    {
        putenv('APP_ENV=production');

        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['action' => 'ps']));

        $envelope = json_decode($tester->getDisplay(), true);
        self::assertSame('observatory-disabled', $envelope['error']);
    }

    #[Test]
    public function monitor_mode_reads_the_journal_but_refuses_replay(): void
    {
        // The operator's SSH session on a production box: ps/tail/show work,
        // because the journal is the whole point of monitor mode — replay does
        // not, because it re-executes recorded requests.
        putenv('APP_ENV=production');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        $this->seedJournal([
            ['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-aa', 'kind' => 'http', 'name' => 'HubPayload', 'worker' => 1],
        ]);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['action' => 'ps']));
        $envelope = json_decode($tester->getDisplay(), true);
        self::assertSame(1, $envelope['counts']['live']);

        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['action' => 'replay', '--id' => 'p-1-aa']));
        $envelope = json_decode($tester->getDisplay(), true);
        self::assertSame('replay-requires-dev', $envelope['error']);
    }
}
