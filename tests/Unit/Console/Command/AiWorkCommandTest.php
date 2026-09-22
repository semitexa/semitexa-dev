<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\ResumeService;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Application\Console\Command\AiWorkCommand;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves ai:work receives its collaborators via the container-level
 * #[InjectAsReadonly] property-injection channel — the same channel used
 * for every other Semitexa service. No constructor DI, no nullable
 * fallbacks, and no manual wiring at the command.
 */
class AiWorkCommandTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-work-cmd-' . uniqid();
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        $this->removeDir($this->root);
    }

    /**
     * Build the command exactly the way the framework builds it at runtime:
     * no-arg constructor, then container-driven property injection.
     */
    private function buildWiredCommand(
        TaskStore $tasks,
        EpicStore $epics,
        TraceStore $traces,
        ResumeService $resume,
    ): AiWorkCommand {
        $container = new ArrayContainer([
            TaskStore::class      => $tasks,
            EpicStore::class      => $epics,
            TraceStore::class     => $traces,
            ResumeService::class  => $resume,
            BacklogHygiene::class => $this->newHygiene($epics, $tasks),
        ]);
        $command = new AiWorkCommand();
        PropertyInjector::inject($command, $container);
        return $command;
    }

    private function newHygiene(EpicStore $epics, TaskStore $tasks): BacklogHygiene
    {
        $hygiene = new BacklogHygiene();
        PropertyInjector::inject($hygiene, new ArrayContainer([
            EpicStore::class => $epics,
            TaskStore::class => $tasks,
        ]));
        return $hygiene;
    }

    private function newEpicStore(TaskStore $tasks): EpicStore
    {
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => $tasks]));
        return $epics;
    }

    private function newResumeService(TaskStore $tasks, TraceStore $traces): ResumeService
    {
        $service = new ResumeService();
        PropertyInjector::inject($service, new ArrayContainer([
            TaskStore::class  => $tasks,
            TraceStore::class => $traces,
        ]));
        return $service;
    }

    public function test_boots_without_any_collaborators(): void
    {
        $command = new AiWorkCommand();
        $this->assertSame('ai:work', $command->getName());
    }

    public function test_start_uses_injected_stores(): void
    {
        $now = '2026-04-19T00:00:00+00:00';
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $epics->save(new Epic('ep-a', 'T', 'G', EpicStatus::NEW, $now, $now));

        $command = $this->buildWiredCommand(
            $tasks,
            $epics,
            $traces,
            $this->newResumeService($tasks, $traces),
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'action'  => 'start',
            '--id'    => 'tk-a',
            '--epic'  => 'ep-a',
            '--title' => 'do it',
            '--json'  => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertTrue($tasks->exists('tk-a'));
        $this->assertTrue($traces->exists('tk-a'));

        $envelope = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($envelope);
        $this->assertSame('semitexa.ai-work.task/v1', $envelope['artifact']);
        $this->assertSame('tk-a', $envelope['task']['id']);
    }

    public function test_resume_uses_injected_resume_service(): void
    {
        $now = '2026-04-19T00:00:00+00:00';
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $epics->save(new Epic('ep-a', 'T', 'G', EpicStatus::NEW, $now, $now));

        $command = $this->buildWiredCommand(
            $tasks,
            $epics,
            $traces,
            $this->newResumeService($tasks, $traces),
        );

        $start = new CommandTester($command);
        $start->execute([
            'action'  => 'start',
            '--id'    => 'tk-a',
            '--epic'  => 'ep-a',
            '--title' => 'do it',
        ]);
        $this->assertSame(0, $start->getStatusCode());

        $resume = new CommandTester($command);
        $resume->execute([
            'action' => 'resume',
            '--id'   => 'tk-a',
            '--json' => true,
        ]);

        $this->assertSame(0, $resume->getStatusCode());
        $envelope = json_decode(trim($resume->getDisplay()), true);
        $this->assertIsArray($envelope);
        $this->assertSame('semitexa.ai-work.task-resume/v1', $envelope['artifact']);
        $this->assertSame('tk-a', $envelope['task']['id']);
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * A note passed to `update` has to reach the trace.
     *
     * It did not. `--note` is declared on the COMMAND and read only by the
     * `note` action, so `ai:work update --status=done --note=...` changed the
     * status, printed the task as updated, exited 0 — and threw the note away.
     * Eighteen task closures in one session recorded their reasoning that way
     * and kept none of it.
     *
     * The exit code is not what this asserts, deliberately: the exit code was
     * always 0, which is exactly why nobody noticed. It asserts the trace.
     */
    public function test_update_records_the_note_it_was_given(): void
    {
        $now = '2026-09-13T00:00:00+00:00';
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $epics->save(new Epic('ep-n', 'T', 'G', EpicStatus::NEW, $now, $now));

        $command = $this->buildWiredCommand($tasks, $epics, $traces, $this->newResumeService($tasks, $traces));

        (new CommandTester($command))->execute([
            'action' => 'start', '--id' => 'tk-n', '--epic' => 'ep-n', '--title' => 'do it', '--json' => true,
        ]);

        $tester = new CommandTester($command);
        $tester->execute([
            'action'   => 'update',
            '--id'     => 'tk-n',
            '--status' => 'done',
            '--note'   => 'why this was closed and what was measured',
            '--json'   => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $recorded = json_encode($traces->read('tk-n'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(
            'why this was closed and what was measured',
            (string) $recorded,
            'the note was accepted and dropped — the reasoning for closing a task is not optional decoration',
        );
    }

    /**
     * And the note alone is enough. Requiring another field to accompany it
     * turned "record why" into "record why AND change something", which is not
     * what a closing note is.
     */
    public function test_update_accepts_a_note_on_its_own(): void
    {
        $now = '2026-09-13T00:00:00+00:00';
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $epics->save(new Epic('ep-o', 'T', 'G', EpicStatus::NEW, $now, $now));

        $command = $this->buildWiredCommand($tasks, $epics, $traces, $this->newResumeService($tasks, $traces));
        (new CommandTester($command))->execute([
            'action' => 'start', '--id' => 'tk-o', '--epic' => 'ep-o', '--title' => 'do it', '--json' => true,
        ]);

        $tester = new CommandTester($command);
        $tester->execute(['action' => 'update', '--id' => 'tk-o', '--note' => 'just the reasoning', '--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString(
            'just the reasoning',
            (string) json_encode($traces->read('tk-o'), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Splitting an epic is the operation people actually perform, and it is
     * this command run once per task. Four of them were moved by editing
     * epic_id in the JSON by hand, because nothing could do it — which works,
     * writes no trace event, and loses the reason for the move.
     */
    public function test_update_re_parents_a_task_to_another_epic(): void
    {
        [$command, $tasks, $epics, $traces] = $this->wiredWithTask('tk-m', 'ep-from');
        $epics->save(new Epic('ep-to', 'T2', 'G2', EpicStatus::NEW, '2026-09-18T00:00:00+00:00', '2026-09-18T00:00:00+00:00'));

        $tester = new CommandTester($command);
        $tester->execute(['action' => 'update', '--id' => 'tk-m', '--epic' => 'ep-to', '--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('ep-to', $tasks->get('tk-m')->epicId, 'the task did not move');

        $envelope = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($envelope);
        $this->assertSame('ep-to', $envelope['task']['epic_id']);

        $recorded = (string) json_encode($traces->read('tk-m'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString("moved ep-from \u{2192} ep-to", $recorded, 'the move is not in the trace');
        $this->assertStringContainsString('ep-from', $recorded, 'the trace must say where it came from');
    }

    /**
     * An unknown epic id is the orphan case BacklogHygiene reports: the task
     * would vanish from every listing that starts at an epic. Refusing is the
     * whole point of validating rather than writing whatever was typed.
     */
    public function test_update_refuses_an_epic_that_does_not_exist(): void
    {
        [$command, $tasks] = $this->wiredWithTask('tk-x', 'ep-real');

        $tester = new CommandTester($command);
        $tester->execute(['action' => 'update', '--id' => 'tk-x', '--epic' => 'ep-imaginary', '--json' => true]);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertSame('ep-real', $tasks->get('tk-x')->epicId, 'a refused move must not half-apply');
        $this->assertStringContainsString('ep-imaginary', $tester->getDisplay());
    }

    public function test_update_refuses_a_malformed_epic_id(): void
    {
        [$command, $tasks] = $this->wiredWithTask('tk-y', 'ep-real');

        $tester = new CommandTester($command);
        $tester->execute(['action' => 'update', '--id' => 'tk-y', '--epic' => 'Not An Id!', '--json' => true]);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertSame('ep-real', $tasks->get('tk-y')->epicId);
    }

    /**
     * Moving a task to the epic it is already in changes nothing, so it must
     * not write a "moved ep-a -> ep-a" event. A trace that records non-moves is
     * a trace people stop reading.
     */
    public function test_moving_a_task_to_its_own_epic_is_not_a_move(): void
    {
        [$command, $tasks, , $traces] = $this->wiredWithTask('tk-s', 'ep-same');

        $tester = new CommandTester($command);
        $tester->execute(['action' => 'update', '--id' => 'tk-s', '--epic' => 'ep-same', '--json' => true]);

        $this->assertNotSame(0, $tester->getStatusCode(), 'nothing was asked for, so this is the "no fields" error');
        $this->assertSame('ep-same', $tasks->get('tk-s')->epicId);

        // The trace is read into a variable and checked for content FIRST: a
        // json_encode() that returned false would cast to '' and satisfy the
        // negative assertion below without ever looking at a trace.
        $recorded = (string) json_encode($traces->read('tk-s'), JSON_UNESCAPED_UNICODE);
        $this->assertNotSame('', $recorded, 'there is no trace to assert against');
        $this->assertStringContainsString('tk-s', $recorded, 'and it is this task\'s trace');
        $this->assertStringNotContainsString('moved', $recorded);
    }

    /**
     * @return array{0: AiWorkCommand, 1: TaskStore, 2: EpicStore, 3: TraceStore}
     */
    private function wiredWithTask(string $taskId, string $epicId): array
    {
        $now = '2026-09-18T00:00:00+00:00';
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $epics->save(new Epic($epicId, 'T', 'G', EpicStatus::NEW, $now, $now));

        $command = $this->buildWiredCommand($tasks, $epics, $traces, $this->newResumeService($tasks, $traces));
        (new CommandTester($command))->execute([
            'action' => 'start', '--id' => $taskId, '--epic' => $epicId, '--title' => 'do it', '--json' => true,
        ]);

        return [$command, $tasks, $epics, $traces];
    }

    #[DataProvider('noteRoundTrips')]
    public function test_note_round_trips_exactly(string $action, bool $json, string $note): void
    {
        [$command, $tasks, , $traces] = $this->wiredWithTask('tk-unicode', 'ep-notes');
        $tester = new CommandTester($command);
        $options = ['action' => $action, '--id' => 'tk-unicode', '--note' => $note, '--json' => $json];
        if ($action === 'update') {
            $options['--status'] = 'done';
        }

        $this->assertSame(0, $tester->execute($options));
        $events = $traces->read('tk-unicode')->events;
        $notes = array_values(array_filter($events, static fn($event) => isset($event->payload['note'])));
        $this->assertCount(1, $notes, 'A successful command must persist exactly one note.');
        $this->assertSame($note, $notes[0]->payload['note']);
        $summary = substr($notes[0]->summary, strlen("note on task 'tk-unicode': "));
        $this->assertLessThanOrEqual(80, mb_strlen($summary, 'UTF-8'));
        if (mb_strlen($note, 'UTF-8') <= 80) {
            $this->assertSame($note, $summary);
        } else {
            $this->assertStringEndsWith('…', $summary);
        }
        $this->assertSame(TraceEventKind::NOTE, $notes[0]->eventKind);
        $this->assertSame('semitexa.ai-work.task-note/v1', $notes[0]->payload['artifact']);
        $this->assertSame(range(1, count($events)), array_column($events, 'eventId'));
        if ($action === 'update') {
            $this->assertSame('done', $tasks->get('tk-unicode')->status->value);
        }
    }

    public static function noteRoundTrips(): iterable
    {
        $notes = [
            'ascii' => str_repeat('a', 120),
            'ukrainian' => str_repeat('я', 60),
            'emoji' => str_repeat('🦈', 25),
            '79 bytes' => str_repeat('a', 77) . 'я',
            '80 bytes' => str_repeat('a', 78) . 'я',
            '81 bytes' => str_repeat('a', 77) . '🦈',
            'long mixed' => str_repeat("Перевірка 🦈 e\u{0301}\n", 100),
        ];
        foreach (['note', 'update'] as $action) {
            foreach ([true, false] as $json) {
                foreach ($notes as $name => $note) {
                    yield $action . '-' . ($json ? 'json' : 'ndjson') . '-' . $name => [$action, $json, $note];
                }
            }
        }
    }

    #[DataProvider('noteFailures')]
    public function test_note_failure_is_reported_with_the_actual_task_state(string $action, bool $json, bool $missingTrace): void
    {
        [$command, $tasks, , $traces] = $this->wiredWithTask('tk-failure', 'ep-notes');
        if ($missingTrace) {
            unlink($traces->pathFor('tk-failure'));
        }
        $tester = new CommandTester($command);
        $options = [
            'action' => $action, '--id' => 'tk-failure', '--json' => $json,
            '--note' => $missingTrace ? 'Keep my reasoning' : "Invalid UTF-8: \xB1",
        ];
        if ($action === 'update') {
            $options['--status'] = 'done';
        }

        $this->assertSame(1, $tester->execute($options));
        $result = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $result[$json ? 'status' : 'kind']);
        $this->assertFalse($result['note_saved']);
        $this->assertSame($action === 'update', $result['task_saved']);
        $this->assertSame($tasks->get('tk-failure')->toArray(), $result['task']);
        if ($action === 'update') {
            $this->assertSame('done', $result['task']['status']);
            $this->assertStringContainsString('Task changes were saved', $result['error']);
        }
        if ($missingTrace) {
            $this->assertFileDoesNotExist($traces->pathFor('tk-failure'));
        } else {
            $notes = array_filter($traces->read('tk-failure')->events, static fn($event) => isset($event->payload['note']));
            $this->assertCount(0, $notes);
            $this->assertStringNotContainsString("\n\n", file_get_contents($traces->pathFor('tk-failure')));
        }
    }

    public static function noteFailures(): iterable
    {
        foreach (['note', 'update'] as $action) {
            foreach ([true, false] as $json) {
                foreach ([true, false] as $missingTrace) {
                    yield [$action, $json, $missingTrace];
                }
            }
        }
    }

    public function test_note_failure_reports_a_next_step_that_was_already_saved(): void
    {
        [$command, $tasks, , $traces] = $this->wiredWithTask('tk-next', 'ep-notes');
        unlink($traces->pathFor('tk-next'));
        $tester = new CommandTester($command);

        $this->assertSame(1, $tester->execute([
            'action' => 'note', '--id' => 'tk-next', '--note' => 'Do not lose this',
            '--next-step' => 'Review the fix', '--json' => true,
        ]));

        $result = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['task_saved']);
        $this->assertFalse($result['note_saved']);
        $this->assertSame('Review the fix', $tasks->get('tk-next')->nextStep);
        $this->assertSame($tasks->get('tk-next')->toArray(), $result['task']);
    }
}
