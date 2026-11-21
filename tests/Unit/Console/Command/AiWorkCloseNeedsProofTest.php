<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiWorkCommand;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionStore;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\ResumeService;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

/** `ai:work update --status=done` through the command: refused with no proof, closed on a reason. */
final class AiWorkCloseNeedsProofTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-work-close-' . uniqid();
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();

        $tasks = new TaskStore();
        $traces = new TraceStore();
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => $tasks]));
        $now = '2026-11-19T00:00:00+00:00';
        $epics->save(new Epic('ep-a', 'T', 'G', EpicStatus::NEW, $now, $now));
        $hygiene = new BacklogHygiene();
        PropertyInjector::inject($hygiene, new ArrayContainer([EpicStore::class => $epics, TaskStore::class => $tasks]));
        $resume = new ResumeService();
        PropertyInjector::inject($resume, new ArrayContainer([TaskStore::class => $tasks, TraceStore::class => $traces]));

        $command = new AiWorkCommand();
        PropertyInjector::inject($command, new ArrayContainer([
            TaskStore::class      => $tasks,
            EpicStore::class      => $epics,
            TraceStore::class     => $traces,
            ResumeService::class  => $resume,
            BacklogHygiene::class => $hygiene,
            DecisionStore::class => new DecisionStore(),
        ]));
        $this->tester = new CommandTester($command);
        // A bug counts from the moment it is filed, so its close is asked for proof.
        $this->tester->execute(['action' => 'start', '--id' => 'tk-a', '--epic' => 'ep-a', '--title' => 'do it', '--recipe' => 'fix_bug', '--json' => true]);
        $this->tester->execute(['action' => 'update', '--id' => 'tk-a', '--status' => 'in_progress', '--json' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, string> $options
     * @return array<mixed> the command's envelope
     */
    private function close(array $options = []): array
    {
        $this->tester->execute(['action' => 'update', '--id' => 'tk-a', '--status' => 'done', '--json' => true] + $options);

        return self::decoded(trim($this->tester->getDisplay()));
    }

    /** @return array<mixed> */
    private static function decoded(string $json): array
    {
        $data = json_decode($json, true);
        self::assertIsArray($data, $json);

        return $data;
    }

    /** What sits at this path, or null: a missing key fails the assertion on it, not the lookup. */
    private static function at(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            $data = is_array($data) ? ($data[$key] ?? null) : null;
        }

        return $data;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @return array{string, string} the task file and its trace, byte for byte */
    private function stored(): array
    {
        return [(string) file_get_contents($this->root . '/var/ai-work/tasks/tk-a.json'), (string) file_get_contents($this->root . '/var/ai-traces/tk-a.ndjson')];
    }

    public function test_a_close_with_no_proof_is_refused_and_changes_nothing(): void
    {
        $before = $this->stored();

        $envelope = $this->close(['--note' => 'closing anyway']);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertSame('error', self::at($envelope, 'status'));
        self::assertStringContainsString("task 'tk-a' has no proof", self::text(self::at($envelope, 'error')));
        self::assertStringContainsString('--no-proof-because="<why>"', self::text(self::at($envelope, 'error')));
        self::assertSame($before, $this->stored(), 'no status, no updated_at, no trace event, no note');
        self::assertSame('in_progress', self::at(self::decoded($before[0]), 'status'));
    }

    public function test_the_refusal_carries_the_run_that_would_prove_it(): void
    {
        $envelope = $this->close();

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertSame('ai:verify', self::at($envelope, 'next_command', 0, 'cmd'));
        self::assertSame(['--trace=tk-a', '--json'], self::at($envelope, 'next_command', 0, 'args'));
        self::assertStringContainsString('is its proof', self::text(self::at($envelope, 'next_command', 0, 'why')));
    }

    public function test_a_blank_reason_is_refused_like_none(): void
    {
        $this->close(['--no-proof-because' => '   ']);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertSame('in_progress', self::at(self::decoded($this->stored()[0]), 'status'));
    }

    public function test_a_close_with_a_reason_writes_it_into_the_task(): void
    {
        $envelope = $this->close(['--no-proof-because' => 'the files are outside this workspace']);

        self::assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $stored = self::decoded($this->stored()[0]);
        self::assertSame('done', self::at($stored, 'status'));
        self::assertArrayHasKey('proof', $stored, 'a missing key would pass the null check below');
        self::assertNull(self::at($stored, 'proof'));
        self::assertSame('the files are outside this workspace', self::at($stored, 'no_proof', 'reason'));
        self::assertNotSame('', self::text(self::at($stored, 'no_proof', 'at')));
        self::assertSame(self::at($stored, 'no_proof'), self::at($envelope, 'task', 'no_proof'));
        $next = self::at($envelope, 'next_command');
        self::assertIsArray($next);
        self::assertSame(['ai:orient', 'ai:trace'], array_column($next, 'cmd'), 'not sent to ai:verify again');
    }

    public function test_reopening_through_the_command_drops_the_reason(): void
    {
        $this->close(['--no-proof-because' => 'the files are outside this workspace']);
        self::assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        self::assertSame('the files are outside this workspace', self::at(self::decoded($this->stored()[0]), 'no_proof', 'reason'), 'the reason is there to be dropped');

        $this->tester->execute(['action' => 'update', '--id' => 'tk-a', '--status' => 'in_progress', '--json' => true]);

        self::assertSame(0, $this->tester->getStatusCode());
        $stored = self::decoded($this->stored()[0]);
        self::assertSame('in_progress', self::at($stored, 'status'));
        self::assertArrayHasKey('no_proof', $stored, 'a missing key would pass the null check below');
        self::assertNull(self::at($stored, 'no_proof'));
    }

    /** Review of part B: the reason was dropped with exit 0 when the task did not close. */
    public function test_a_reason_without_a_close_is_an_error_not_dropped(): void
    {
        $before = $this->stored();

        $this->tester->execute(['action' => 'update', '--id' => 'tk-a', '--note' => 'later', '--no-proof-because' => 'the files are outside this workspace', '--json' => true]);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertStringContainsString('--no-proof-because closes a task: use it with --status=done', self::text(self::at(self::decoded(trim($this->tester->getDisplay())), 'error')));
        self::assertSame($before, $this->stored());
    }

    /** Review of part B: a one-character reason was stored and counted. */
    public function test_a_reason_too_short_to_judge_is_refused(): void
    {
        $before = $this->stored();

        $envelope = $this->close(['--no-proof-because' => 'x']);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertStringContainsString('--no-proof-because needs at least 20 characters', self::text(self::at($envelope, 'error')));
        self::assertSame($before, $this->stored());
    }
}
