<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiWorkIntakeCommand;
use Semitexa\Dev\Application\Service\Ai\Intake\RuntimeIntake;
use Semitexa\Dev\Application\Service\Ai\Intake\RuntimeSignal;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskFiling;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

final class AiWorkIntakeCommandTest extends TestCase
{
    private const HANDLER = 'src/modules/Shop/Handler.php';

    private string $root;
    private ?string $originalCwd = null;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-intake-' . uniqid('', true);
        mkdir($this->root . '/var/log', 0755, true);
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
        self::assertSame($this->root, ProjectRoot::get(), 'the intake must never write into the real backlog');
        foreach (['LOG_SINK', 'LOG_FILE'] as $key) {
            $this->env[$key] = getenv($key);
        }
        putenv('LOG_SINK=file');
        putenv('LOG_FILE=');
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, mixed> $context */
    private function entry(string $message, array $context = [], string $at = '2026-11-20T10:00:00+00:00', string $channel = 'pipeline', string $level = 'error'): string
    {
        return json_encode(['timestamp' => $at, 'level' => $level, 'message' => $message, 'context' => $context, 'channel' => $channel], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function unhandled(string $class = 'Shop\\OutOfStock', string $file = self::HANDLER . ':42', string $at = '2026-11-20T10:00:00+00:00', string $message = 'item 17 is gone', string $path = '/shop/17'): string
    {
        return $this->entry('Unhandled exception during route execution', [
            'route' => 'ShopPayload',
            'path' => $path,
            'method' => 'GET',
            'exception' => $class,
            'message' => $message,
            'file' => $this->root . '/' . $file,
            'trace' => '#0 ' . $this->root . '/' . $file . ': {main}',
        ], $at);
    }

    private function log(string ...$lines): void
    {
        file_put_contents($this->root . '/var/log/app.log', implode('', $lines), FILE_APPEND);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{int, array<string, mixed>}
     */
    private function intake(array $input = []): array
    {
        $tasks = new TaskStore();
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => $tasks]));
        $intake = new RuntimeIntake();
        PropertyInjector::inject($intake, new ArrayContainer([TaskStore::class => $tasks, EpicStore::class => $epics, TraceStore::class => new TraceStore()]));
        $command = new AiWorkIntakeCommand();
        PropertyInjector::inject($command, new ArrayContainer([RuntimeIntake::class => $intake]));
        $tester = new CommandTester($command);
        $status = $tester->execute($input, ['interactive' => false]);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, $tester->getDisplay());

        /** @var array<string, mixed> $decoded */
        return [$status, $decoded];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function written(array $input = []): array
    {
        [$status, $out] = $this->intake(['--write' => true] + $input);
        self::assertSame(0, $status, json_encode($out) ?: '');

        return $out;
    }

    /** @param array<string, mixed> $out */
    private function onlyId(array $out, string $key = 'new'): string
    {
        self::assertIsArray($out[$key]);
        self::assertCount(1, $out[$key]);
        self::assertIsArray($out[$key][0]);
        self::assertIsString($out[$key][0]['id']);

        return $out[$key][0]['id'];
    }

    /**
     * @param array<string, mixed> $out
     * @return array<mixed>
     */
    private function first(array $out, string $key = 'new'): array
    {
        self::assertIsArray($out[$key]);
        self::assertArrayHasKey(0, $out[$key]);
        self::assertIsArray($out[$key][0]);

        return $out[$key][0];
    }

    /** @return list<string> */
    private function taskFiles(): array
    {
        return glob($this->root . '/' . TaskStore::SUBDIR . '/*.json') ?: [];
    }

    public function test_a_dry_run_says_what_it_would_file_and_writes_nothing(): void
    {
        $this->log($this->unhandled(at: '2026-11-20T10:00:00+00:00'), $this->unhandled(file: self::HANDLER . ':57', at: '2026-11-20T11:00:00+00:00', message: 'item 99 is gone'));

        [$status, $out] = $this->intake();

        self::assertSame(0, $status);
        self::assertSame('dry-run', $out['mode']);
        self::assertSame(1, $out['signals'], 'another line, id and time are the same signal');
        $this->onlyId($out);
        self::assertSame(2, $this->first($out)['count']);
        self::assertSame([], $this->taskFiles());
        self::assertDirectoryDoesNotExist($this->root . '/var/ai-work/epics');
        self::assertSame('ai:work:intake', $this->first($out, 'next_command')['cmd']);
    }

    public function test_write_files_an_unapproved_idea_that_counts_under_work_once_approved(): void
    {
        $this->log($this->unhandled(at: '2026-11-20T10:00:00+00:00'), $this->unhandled(at: '2026-11-20T11:00:00+00:00'));

        $out = $this->written();
        $id = $this->onlyId($out);
        $task = (new TaskStore())->get($id);

        self::assertStringStartsWith(RuntimeSignal::ID_PREFIX, $id);
        self::assertTrue($out['epic_created']);
        self::assertSame(RuntimeIntake::EPIC, $task->epicId);
        self::assertTrue((new EpicStore())->exists(RuntimeIntake::EPIC));
        self::assertNull($task->approval, 'a signal is a candidate: the owner says whether it is a defect');
        self::assertFalse(TaskFiling::isBug($task->recipe), 'a bug recipe would approve it by rule');
        self::assertSame(Phase::WORK, $task->phase, 'with no phase an approval would count nowhere');
        self::assertSame(TaskStatus::NEW, $task->status);
        self::assertSame('Runtime error: OutOfStock in src/modules/Shop/Handler.php (pipeline, route ShopPayload)', $task->title);
        self::assertSame([self::HANDLER], $task->contextRefs);
        self::assertSame(
            RuntimeSignal::NEXT_STEP_PREFIX . ' seen 2 times in var/log/app.log, from 2026-11-20T10:00:00+00:00 to 2026-11-20T11:00:00+00:00. Read it: bin/semitexa logs:app --around=2026-11-20T11:00:00+00:00 --grep=OutOfStock. A defect: approve it (bin/semitexa ai:work:approve --id=' . $id . ' --words="<the owner\'s words>"); the environment or a test: bin/semitexa ai:work update --id=' . $id . ' --status=discarded.',
            $task->nextStep,
        );
        self::assertTrue((new TraceStore())->exists($id));
    }

    public function test_a_second_run_files_nothing_twice(): void
    {
        $this->log($this->unhandled());
        $id = $this->onlyId($this->written());
        $before = (new TaskStore())->get($id);

        $again = $this->written();

        self::assertSame([], $again['new']);
        self::assertSame([], $again['seen_again']);
        self::assertSame(1, $again['unchanged']);
        self::assertFalse($again['epic_created']);
        self::assertCount(1, $this->taskFiles());
        self::assertSame($before->toArray(), (new TaskStore())->get($id)->toArray());
    }

    public function test_an_untouched_idea_seen_again_gets_the_new_count(): void
    {
        $this->log($this->unhandled(at: '2026-11-20T10:00:00+00:00'));
        $id = $this->onlyId($this->written());
        $this->log($this->unhandled(at: '2026-11-20T12:00:00+00:00'), $this->unhandled(at: '2026-11-20T13:00:00+00:00'));

        $again = $this->written();

        self::assertSame($id, $this->onlyId($again, 'seen_again'));
        self::assertSame(3, $this->first($again, 'seen_again')['count']);
        self::assertSame([], $again['new']);
        $task = (new TaskStore())->get($id);
        self::assertStringContainsString('seen 3 times', (string) $task->nextStep);
        self::assertStringContainsString('to 2026-11-20T13:00:00+00:00', (string) $task->nextStep);
        self::assertNull($task->approval);
    }

    public function test_a_log_path_with_a_space_still_reads_as_the_intakes_own(): void
    {
        putenv('LOG_FILE=var/log/app errors.log');
        $spaced = $this->root . '/var/log/app errors.log';
        file_put_contents($spaced, $this->unhandled(at: '2026-11-20T10:00:00+00:00'));
        $id = $this->onlyId($this->written());
        file_put_contents($spaced, $this->unhandled(at: '2026-11-20T12:00:00+00:00'), FILE_APPEND);

        $again = $this->written();

        self::assertSame($id, $this->onlyId($again, 'seen_again'), 'the generated next step is still recognised, so the count moves');
        self::assertStringContainsString('seen 2 times in var/log/app errors.log, from 2026-11-20T10:00:00+00:00 to 2026-11-20T12:00:00+00:00.', (string) (new TaskStore())->get($id)->nextStep);
    }

    public function test_a_task_someone_approved_or_wrote_on_is_left_as_it_is(): void
    {
        $this->log($this->unhandled(), $this->unhandled(class: 'Shop\\NoPrice', file: 'src/modules/Shop/Price.php:9'));
        $out = $this->written();
        self::assertIsArray($out['new']);
        self::assertCount(2, $out['new']);
        [$approved, $noted] = array_column($out['new'], 'id');
        self::assertIsString($approved);
        self::assertIsString($noted);
        $store = new TaskStore();
        $store->change($approved, static fn (Task $t): Task => $t->withApproval(new TaskApproval('owner', 'Так, це баг', '2026-11-20T12:00:00+00:00'), '2026-11-20T12:00:00+00:00'));
        $store->change($noted, static fn (Task $t): Task => $t->with(nextStep: 'Root cause: the price cache; fix in Price::of'));
        $this->log($this->unhandled(at: '2026-11-20T14:00:00+00:00'), $this->unhandled(class: 'Shop\\NoPrice', file: 'src/modules/Shop/Price.php:9', at: '2026-11-20T14:00:00+00:00'));

        $again = $this->written();

        self::assertSame([], $again['seen_again']);
        self::assertIsArray($again['kept']);
        self::assertCount(2, $again['kept']);
        self::assertSame('owner', $store->get($approved)->approval?->by);
        self::assertStringContainsString('seen 1 time ', (string) $store->get($approved)->nextStep, 'an approved task is the owner\'s now');
        self::assertSame('Root cause: the price cache; fix in Price::of', $store->get($noted)->nextStep);
    }

    public function test_an_idea_the_owner_wrote_into_keeps_every_word(): void
    {
        $classes = ['Shop\\Appended', 'Shop\\Prepended', 'Shop\\Reworded'];
        foreach ($classes as $class) {
            $this->log($this->unhandled(class: $class));
        }
        $out = $this->written();
        self::assertIsArray($out['new']);
        self::assertCount(3, $out['new']);
        $store = new TaskStore();
        $edited = [];
        foreach (array_column($out['new'], 'id', 'title') as $title => $id) {
            self::assertIsString($id);
            $generated = (string) $store->get($id)->nextStep;
            self::assertStringStartsWith(RuntimeSignal::NEXT_STEP_PREFIX, $generated);
            $edited[$id] = match (true) {
                str_contains((string) $title, 'Appended') => $generated . "\n\nSeen it: the cart is empty when the stock sync lags.",
                str_contains((string) $title, 'Prepended') => 'Checked with the shop owner. ' . $generated,
                default => str_replace('A defect:', 'Most likely a defect:', $generated),
            };
            self::assertNotSame($generated, $edited[$id]);
            $next = $edited[$id];
            $store->change($id, static fn (Task $t): Task => $t->with(nextStep: $next));
        }
        foreach ($classes as $class) {
            $this->log($this->unhandled(class: $class, at: '2026-11-20T16:00:00+00:00'));
        }

        $again = $this->written();

        self::assertSame([], $again['seen_again']);
        self::assertIsArray($again['kept']);
        $kept = array_column($again['kept'], 'id');
        sort($kept);
        $ids = array_keys($edited);
        sort($ids);
        self::assertSame($ids, $kept);
        foreach ($edited as $id => $nextStep) {
            self::assertSame($nextStep, $store->get($id)->nextStep);
        }
    }

    public function test_the_follow_up_keeps_the_epic_and_the_limit(): void
    {
        $tasks = new TaskStore();
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => $tasks]));
        $epics->save(new Epic('ep-shop', 'Shop', 'The shop works', EpicStatus::NEW, '2026-11-20T00:00:00+00:00', '2026-11-20T00:00:00+00:00'));
        $this->log($this->unhandled(class: 'Shop\\One'), $this->unhandled(class: 'Shop\\Two'), $this->unhandled(class: 'Shop\\Three'));
        $intake = ['cmd' => 'ai:work:intake', 'args' => ['--write', '--epic=ep-shop', '--limit=1', '--json']];

        [, $dry] = $this->intake(['--epic' => 'ep-shop', '--limit' => '1']);

        self::assertSame([$intake + ['why' => 'file the new signals as ideas and update the counts']], $dry['next_command']);

        $out = $this->written(['--epic' => 'ep-shop', '--limit' => '1']);

        self::assertSame([
            ['cmd' => 'ai:work', 'args' => ['list', '--epic=ep-shop', '--json'], 'why' => 'show the owner the ideas: approve the defects (ai:work:approve --id=<id>), discard the environment'],
            $intake + ['why' => '2 more signals wait past --limit'],
        ], $out['next_command']);
    }

    public function test_a_closed_signal_is_not_filed_again(): void
    {
        $this->log($this->unhandled());
        $id = $this->onlyId($this->written());
        (new TaskStore())->change($id, static fn (Task $t): Task => $t->with(status: TaskStatus::DISCARDED));
        $this->log($this->unhandled(at: '2026-11-20T15:00:00+00:00'));

        $again = $this->written();

        self::assertSame($id, $this->onlyId($again, 'closed'));
        self::assertSame('discarded', $this->first($again, 'closed')['status']);
        self::assertSame([], $again['new']);
        self::assertSame(TaskStatus::DISCARDED, (new TaskStore())->get($id)->status);
    }

    public function test_the_exception_message_and_the_request_never_reach_the_backlog(): void
    {
        $this->log(
            $this->unhandled(message: 'password=hunter2 rejected for alice@example.com', path: '/reset?token=s3cr3t-reset-token'),
            $this->entry('Login failed for user=bob password=hunter2', channel: 'auth'),
        );

        $out = $this->written();

        self::assertIsArray($out['new']);
        self::assertCount(2, $out['new']);
        self::assertContains('Runtime error: Login failed for… (auth)', array_column($out['new'], 'title'));
        $written = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/var/ai-work', \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $written .= (string) file_get_contents($file->getPathname());
        }
        self::assertNotSame('', $written);
        $envelope = json_encode($out, JSON_THROW_ON_ERROR);
        self::assertJson($envelope);
        foreach (['hunter2', 'alice@example.com', 's3cr3t-reset-token', '/reset', 'bob'] as $secret) {
            self::assertStringNotContainsString($secret, $written);
            self::assertStringNotContainsString($secret, $envelope);
        }
    }

    public function test_an_outage_is_counted_and_not_filed(): void
    {
        $this->log(
            $this->entry('Redis SSE publish failed', ['exception' => 'Predis\\Connection\\ConnectionException', 'message' => 'Connection refused [tcp://127.0.0.1:6379]'], channel: 'ssr'),
            $this->entry('Redis SSE publish failed', ['exception' => 'Predis\\Connection\\ConnectionException', 'message' => 'Connection refused [tcp://127.0.0.1:6379]'], channel: 'ssr'),
        );

        $out = $this->written();

        self::assertSame(['Predis\\Connection\\ConnectionException' => 2], $out['skipped_environment']);
        self::assertSame(0, $out['signals']);
        self::assertSame([], $out['new']);
        self::assertSame([], $this->taskFiles());
        self::assertFalse($out['epic_created'], 'nothing to file, no epic');
    }

    public function test_the_reference_says_whose_code_it_is(): void
    {
        $this->log(
            $this->unhandled(class: 'Semitexa\\Orm\\Exception\\Lost', file: 'packages/semitexa-orm/src/Adapter.php:10'),
            $this->entry('Collaborative form event failed', ['exception' => 'Semitexa\\Orm\\Exception\\ConstraintViolationException', 'message' => 'dup'], channel: 'platform-ui'),
            $this->entry('Dashboard widget failed', ['message' => 'x'], channel: 'platform-ui'),
        );

        [, $out] = $this->intake();

        self::assertIsArray($out['new']);
        $refs = array_column($out['new'], 'context_refs', 'title');
        $expected = [
            'Runtime error: Lost in packages/semitexa-orm/src/Adapter.php (pipeline, route ShopPayload)' => ['packages/semitexa-orm/src/Adapter.php'],
            'Runtime error: ConstraintViolationException: Collaborative form event failed (platform-ui)' => ['Semitexa\\Orm\\Exception\\ConstraintViolationException'],
            'Runtime error: Dashboard widget failed (platform-ui)' => [],
        ];
        ksort($refs);
        ksort($expected);
        self::assertSame($expected, $refs, 'a framework file, a framework class, and nothing to say whose');
    }

    public function test_only_the_entrys_own_level_makes_an_error(): void
    {
        $this->log(
            $this->entry('Retry scheduled', ['level' => 'error', 'exception' => 'Shop\\Late'], level: 'warning'),
            '{"timestamp":"2026-11-20T10:00:00+00:00","level":"error","message":"cut sho' . "\n",
        );

        [, $out] = $this->intake();

        self::assertSame(['lines' => 2, 'errors' => 0, 'unreadable' => 1], $out['read']);
        self::assertSame(0, $out['signals']);
    }

    public function test_a_log_sent_to_stderr_is_said_not_read_as_silence(): void
    {
        $this->log($this->unhandled());
        putenv('LOG_SINK=stderr');

        [$status, $out] = $this->intake(['--write' => true]);

        self::assertSame(0, $status);
        self::assertSame(['sink' => 'stderr', 'file' => null], $out['source']);
        self::assertIsString($out['note']);
        self::assertStringContainsString('LOG_SINK=stderr', $out['note']);
        self::assertArrayNotHasKey('new', $out);
        self::assertSame([], $this->taskFiles());
    }

    public function test_a_log_that_is_not_there_yet_is_said(): void
    {
        putenv('LOG_FILE=var/log/elsewhere.log');
        $this->log($this->unhandled());

        [$status, $out] = $this->intake();

        self::assertSame(0, $status);
        self::assertSame(['sink' => 'file', 'file' => 'var/log/elsewhere.log'], $out['source']);
        self::assertIsString($out['note']);
        self::assertStringContainsString('does not exist yet', $out['note']);
    }

    public function test_the_limit_takes_the_loudest_first(): void
    {
        $this->log(
            $this->unhandled(class: 'Shop\\Rare'),
            $this->unhandled(class: 'Shop\\Loud'), $this->unhandled(class: 'Shop\\Loud'), $this->unhandled(class: 'Shop\\Loud'),
            $this->unhandled(class: 'Shop\\Middle'), $this->unhandled(class: 'Shop\\Middle'),
        );

        $out = $this->written(['--limit' => '2']);

        self::assertIsArray($out['new']);
        self::assertSame([3, 2], array_column($out['new'], 'count'));
        self::assertSame(1, $out['over_limit']);
        self::assertCount(2, $this->taskFiles());
        self::assertIsArray($out['next_command']);
        self::assertContains('ai:work:intake', array_column($out['next_command'], 'cmd'), 'the rest waits for the next run');
    }

    public function test_a_bad_limit_and_an_unknown_epic_are_refused(): void
    {
        $this->log($this->unhandled());

        [$status, $out] = $this->intake(['--limit' => '0']);
        self::assertSame(1, $status);
        self::assertIsString($out['error']);
        self::assertStringContainsString('--limit', $out['error']);

        [$status, $out] = $this->intake(['--write' => true, '--epic' => 'ep-nope']);
        self::assertSame(1, $status);
        self::assertIsString($out['error']);
        self::assertStringContainsString("epic 'ep-nope' does not exist", $out['error']);
        self::assertSame([], $this->taskFiles());
    }
}
