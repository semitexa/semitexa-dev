<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Presence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentDocsFreshness;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentRegistry;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentSession;
use Semitexa\Dev\Application\Service\Ai\Presence\BacklogActivity;
use Semitexa\Dev\Application\Service\Ai\Presence\TaskClaim;
use Semitexa\Dev\Application\Service\Ai\Presence\WorkspaceActivity;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;

/**
 * Two agents in one checkout used to learn about each other by collision —
 * foreign dirty files, a stack restarted under them, a task "in progress" from
 * a session long gone. These pin what they can now see and what they are
 * stopped from doing blind.
 */
final class AgentPresenceTest extends TestCase
{
    private string $root;
    private string|false $envBefore;
    /** @var array<string, string|false> what the container exported, put back after each test */
    private array $presenceEnv = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-presence-' . uniqid();
        mkdir($this->root, 0o755, true);
        $this->envBefore = getenv(AgentRegistry::ENV);
        foreach ([AgentRegistry::DIR_ENV, AgentRegistry::HOST_ROOT_ENV, AgentRegistry::IMPLICIT_ENV] as $name) {
            $this->presenceEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->presenceEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        $this->envBefore === false ? putenv(AgentRegistry::ENV) : putenv(AgentRegistry::ENV . '=' . $this->envBefore);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_join_needs_a_name_and_an_intent_the_others_can_read(): void
    {
        $registry = new AgentRegistry($this->root);

        foreach ([['Codex The Great', 'writing the demo article'], ['codex', 'demo']] as [$name, $intent]) {
            try {
                $registry->join($name, $intent);
                self::fail("join accepted name '{$name}' / intent '{$intent}'");
            } catch (\InvalidArgumentException) {
            }
        }

        $session = $registry->join('codex', 'writing the Semitexa Demo SSR article', ['packages/semitexa-demo']);
        self::assertMatchesRegularExpression('/^codex-[0-9a-f]{6}$/', $session->id);
        self::assertSame([$session->id], array_map(static fn (AgentSession $s): string => $s->id, $registry->all()));
    }

    #[Test]
    public function a_session_that_went_quiet_or_left_is_not_live(): void
    {
        $registry = new AgentRegistry($this->root);
        $quiet = $registry->join('claude', 'something that stopped halfway');
        $gone = $registry->join('codex', 'something that finished properly');
        $registry->leave($gone->id);

        $later = time() + AgentSession::LIVE_SECONDS + 60;

        self::assertSame([], $registry->all(false, $later));
        self::assertCount(1, $registry->all(false), 'the quiet one is live until its window passes; the one that left is not');
        self::assertCount(2, $registry->all(true));
        self::assertSame($quiet->id, $registry->all(false)[0]->id);
    }

    #[Test]
    public function a_task_a_live_agent_holds_is_refused_to_another_and_released_when_done(): void
    {
        $registry = new AgentRegistry($this->root);
        $claude = $registry->join('claude', 'building agent presence');
        $codex = $registry->join('codex', 'writing the demo article');

        putenv(AgentRegistry::ENV . '=' . $claude->id);
        self::assertNull(TaskClaim::claim($this->root, 'tk-shared', TaskStatus::IN_PROGRESS, false));

        putenv(AgentRegistry::ENV . '=' . $codex->id);
        $refused = TaskClaim::claim($this->root, 'tk-shared', TaskStatus::IN_PROGRESS, false);
        self::assertNotNull($refused);
        self::assertStringContainsString($claude->id, $refused);
        self::assertStringContainsString('building agent presence', $refused, 'the refusal says what the holder is doing');

        putenv(AgentRegistry::ENV . '=' . $claude->id);
        self::assertNull(TaskClaim::claim($this->root, 'tk-shared', TaskStatus::DONE, false));
        putenv(AgentRegistry::ENV . '=' . $codex->id);
        self::assertNull(TaskClaim::claim($this->root, 'tk-shared', TaskStatus::IN_PROGRESS, false), 'a finished task is free again');
        self::assertSame('tk-shared', $registry->get($codex->id)?->task);
    }

    #[Test]
    public function take_over_moves_the_task_and_leaves_the_old_holder_empty_handed(): void
    {
        $registry = new AgentRegistry($this->root);
        $a = $registry->join('claude', 'holding a task it will not finish');
        $b = $registry->join('codex', 'taking that task over with consent');
        putenv(AgentRegistry::ENV . '=' . $a->id);
        self::assertNull(TaskClaim::claim($this->root, 'tk-x', TaskStatus::IN_PROGRESS, false));
        self::assertSame('tk-x', $this->session($registry, $a->id)->task, 'A must hold the task before B can take it over');

        putenv(AgentRegistry::ENV . '=' . $b->id);
        self::assertNull(TaskClaim::claim($this->root, 'tk-x', TaskStatus::IN_PROGRESS, true));

        self::assertNull($this->session($registry, $a->id)->task);
        self::assertSame('tk-x', $this->session($registry, $b->id)->task);
    }

    #[Test]
    public function any_update_to_a_held_task_is_refused_not_only_taking_it(): void
    {
        // A title edit (no status) or a `done` used to skip the holder check.
        $registry = new AgentRegistry($this->root);
        $a = $registry->join('claude', 'working tk-y');
        $b = $registry->join('codex', 'about to touch tk-y');
        putenv(AgentRegistry::ENV . '=' . $a->id);
        self::assertNull(TaskClaim::claim($this->root, 'tk-y', TaskStatus::IN_PROGRESS, false));

        putenv(AgentRegistry::ENV . '=' . $b->id);
        self::assertStringContainsString($a->id, (string) TaskClaim::claim($this->root, 'tk-y', null, false));
        self::assertStringContainsString($a->id, (string) TaskClaim::claim($this->root, 'tk-y', TaskStatus::DONE, false));
        self::assertSame('tk-y', $this->session($registry, $a->id)->task);
    }

    #[Test]
    public function an_ended_session_cannot_claim_and_is_not_current(): void
    {
        $registry = new AgentRegistry($this->root);
        $a = $registry->join('claude', 'left already');
        putenv(AgentRegistry::ENV . '=' . $a->id);
        $registry->leave($a->id);

        self::assertNull($registry->current());
        self::assertStringContainsString('not a live session', (string) TaskClaim::claim($this->root, 'tk-z', TaskStatus::IN_PROGRESS, false));
    }

    #[Test]
    public function a_repo_git_cannot_read_is_reported_not_taken_for_clean(): void
    {
        $repo = $this->root . '/packages/semitexa-broken';
        mkdir($repo . '/.git', 0o755, true);  // a .git with nothing in it: git status fails

        $rows = (new WorkspaceActivity($this->root))->dirtyRepos([]);

        self::assertCount(1, $rows);
        self::assertSame('packages/semitexa-broken', $rows[0]['repo']);
        self::assertFalse($rows[0]['readable']);
    }

    #[Test]
    public function a_claim_whose_lock_cannot_be_taken_is_a_refusal_not_an_exception(): void
    {
        $registry = new AgentRegistry($this->root);
        $a = $registry->join('claude', 'claiming while the registry lock is broken');
        putenv(AgentRegistry::ENV . '=' . $a->id);
        $lock = $this->root . '/' . AgentRegistry::SUBDIR . '/.lock';
        @unlink($lock);
        mkdir($lock);  // a directory where the lock file should be: fopen fails, as root too

        try {
            self::assertStringContainsString('could not update the claim', (string) TaskClaim::claim($this->root, 'tk-w', TaskStatus::IN_PROGRESS, false));
        } finally {
            rmdir($lock);
        }
    }

    #[Test]
    public function a_heartbeat_after_a_take_over_does_not_revive_the_old_claim(): void
    {
        $registry = new AgentRegistry($this->root);
        $a = $registry->join('claude', 'went quiet holding tk-q');
        $b = $registry->join('codex', 'took tk-q over');
        putenv(AgentRegistry::ENV . '=' . $a->id);
        self::assertNull(TaskClaim::claim($this->root, 'tk-q', TaskStatus::IN_PROGRESS, false));
        putenv(AgentRegistry::ENV . '=' . $b->id);
        self::assertNull(TaskClaim::claim($this->root, 'tk-q', TaskStatus::IN_PROGRESS, true));

        // A's stale copy still says tk-q (as if the release had been lost), then A beats.
        $path = $this->root . '/' . AgentRegistry::SUBDIR . '/' . $a->id . '.json';
        $row = json_decode((string) file_get_contents($path), true);
        $row['task'] = 'tk-q';
        file_put_contents($path, json_encode($row));
        putenv(AgentRegistry::ENV . '=' . $a->id);
        $registry->beat();

        self::assertNull($this->session($registry, $a->id)->task);
        self::assertSame('tk-q', $this->session($registry, $b->id)->task);
    }

    private function session(AgentRegistry $registry, string $id): AgentSession
    {
        $session = $registry->get($id);
        self::assertNotNull($session, "session {$id} must be readable");

        return $session;
    }

    #[Test]
    public function uncommitted_edits_are_seen_whoever_made_them_and_matched_to_a_claim(): void
    {
        $repo = $this->root . '/packages/semitexa-demo';
        mkdir($repo, 0o755, true);
        exec('git -C ' . escapeshellarg($repo) . ' init -q 2>&1');
        file_put_contents($repo . '/article.md', 'draft');

        $activity = new WorkspaceActivity($this->root);
        $unclaimed = $activity->dirtyRepos([]);
        self::assertCount(1, $unclaimed);
        self::assertSame('packages/semitexa-demo', $unclaimed[0]['repo']);
        self::assertTrue($unclaimed[0]['fresh']);
        self::assertSame([], $unclaimed[0]['claimed_by']);

        $codex = (new AgentRegistry($this->root))->join('codex', 'writing the demo article', ['packages/semitexa-demo']);
        self::assertSame([$codex->id], $activity->dirtyRepos([$codex])[0]['claimed_by']);
    }

    #[Test]
    public function an_agent_of_another_project_is_seen_but_never_holds_this_projects_task(): void
    {
        // One machine-wide store, as bin/semitexa mounts it: a portal session
        // edited the workspace's packages and backlog while the workspace said
        // "nobody has joined" (2026-10-17).
        $store = $this->root . '/store';
        $portal = new AgentRegistry($this->root . '/portal', $store);
        $dev = new AgentRegistry($this->root . '/dev', $store);
        $theirs = $portal->join('claude', 'releasing the llms.txt contributors', ['packages/semitexa-site']);
        $mine = $dev->join('claude', 'building the shared presence store');

        $seen = array_map(static fn (AgentSession $s): string => $s->id, $dev->all());
        sort($seen);
        $expected = [$theirs->id, $mine->id];
        sort($expected);
        self::assertSame($expected, $seen, 'each project sees every agent on the machine');
        self::assertSame($this->root . '/portal', $dev->get($theirs->id)?->project);
        self::assertSame([$mine->id], array_map(static fn (AgentSession $s): string => $s->id, $dev->ofThisProject($dev->all())));

        // Same task id, two backlogs: the portal's llms-2 is not this workspace's llms-2.
        putenv(AgentRegistry::ENV . '=' . $theirs->id);
        $portal->recordTask($portal->current() ?? self::fail('the portal session must be current'), 'llms-2');
        self::assertNull($dev->holderOf('llms-2', $mine->id));
        self::assertSame($theirs->id, $portal->holderOf('llms-2', null)?->id);
    }

    #[Test]
    public function a_task_taken_in_another_projects_backlog_is_held_there_not_where_the_agent_joined(): void
    {
        // The portal session ran the workspace's bin/semitexa and took the
        // workspace's llms-2: the claim must stop a workspace agent, not a portal one.
        $store = $this->root . '/store';
        $portal = new AgentRegistry($this->root . '/portal', $store);
        $dev = new AgentRegistry($this->root . '/dev', $store);
        $visitor = $portal->join('claude', 'releasing the llms.txt contributors');
        $local = $dev->join('codex', 'working the workspace backlog');

        $dev->recordTask($visitor, 'llms-2');

        self::assertSame($visitor->id, $dev->holderOf('llms-2', $local->id)?->id);
        self::assertNull($portal->holderOf('llms-2', null), "the portal's own llms-2 is free");

        // A beat from the visitor's own project must not drop a task it holds elsewhere.
        putenv(AgentRegistry::ENV . '=' . $visitor->id);
        $portal->beat();
        self::assertSame('llms-2', $portal->get($visitor->id)?->task);
    }

    #[Test]
    public function a_record_written_before_sessions_named_a_project_belongs_to_its_store(): void
    {
        $registry = new AgentRegistry($this->root);
        $session = $registry->join('codex', 'an agent from before the shared store');
        $path = $this->root . '/' . AgentRegistry::SUBDIR . '/' . $session->id . '.json';
        $row = json_decode((string) file_get_contents($path), true);
        unset($row['project']);
        file_put_contents($path, json_encode($row));

        self::assertSame($this->root, $registry->get($session->id)?->project);
        self::assertCount(1, $registry->ofThisProject($registry->all()));
    }

    #[Test]
    public function only_the_project_the_process_runs_in_uses_the_mounted_store(): void
    {
        $cwd = getcwd();
        $shared = $this->root . '/home/agents';
        $project = $this->root . '/app';
        mkdir($project . '/src/modules', 0o755, true);
        file_put_contents($project . '/composer.json', '{}');
        putenv(AgentRegistry::DIR_ENV . '=' . $shared);
        putenv(AgentRegistry::HOST_ROOT_ENV . '=/home/dev/projects/app');
        try {
            // A scratch root (a test, a fixture) keeps its own store: it must not
            // show up as a live agent to everyone on the machine.
            $scratch = new AgentRegistry($this->root . '/scratch');
            self::assertSame($this->root . '/scratch/' . AgentRegistry::SUBDIR, $scratch->dir());
            self::assertSame($this->root . '/scratch', $scratch->project());

            chdir($project);
            ProjectRoot::reset();
            $here = new AgentRegistry($project);
            self::assertSame($shared, $here->dir());
            self::assertSame('/home/dev/projects/app', $here->project(), 'inside the container every project is /var/www/html');
            self::assertSame('/home/dev/projects/app', $here->join('claude', 'joining from inside a container')->project);
            self::assertFileExists($shared . '/' . $here->all()[0]->id . '.json');
        } finally {
            putenv(AgentRegistry::DIR_ENV);
            putenv(AgentRegistry::HOST_ROOT_ENV);
            if ($cwd !== false) {
                chdir($cwd);
            }
            ProjectRoot::reset();
        }
    }

    #[Test]
    public function an_agent_bin_semitexa_recognised_is_listed_from_its_first_command_without_joining(): void
    {
        // Claude Code starts a fresh shell per command, so the export join
        // prints is lost and the session read as "nobody has joined" (2026-10-17).
        $registry = new AgentRegistry($this->root);
        putenv(AgentRegistry::ENV . '=claude-a339fb');
        putenv(AgentRegistry::IMPLICIT_ENV . '=claude');

        $me = $registry->current();
        self::assertNotNull($me);
        self::assertSame(['claude-a339fb', 'claude', false], [$me->id, $me->agent, $me->joined]);
        self::assertSame(['claude-a339fb'], array_map(static fn (AgentSession $s): string => $s->id, $registry->all()));
        self::assertNull(TaskClaim::claim($this->root, 'tk-x', TaskStatus::IN_PROGRESS, false), 'it can take a task like a joined agent');

        $joined = $registry->join('claude', 'building implicit presence', ['packages/semitexa-dev']);
        self::assertSame('claude-a339fb', $joined->id, 'joining names the session it already reports to');
        self::assertTrue($joined->joined);
        self::assertSame('tk-x', $joined->task, 'and keeps the task it holds');
        self::assertCount(1, $registry->all());

        $registry->leave($joined->id);
        self::assertNotNull($registry->get($joined->id)?->endedAt, 'leave ends the session');
        $again = $registry->current();
        self::assertNotNull($again, 'an agent that left and runs commands again is working again');
        self::assertNull($again->endedAt);
    }

    #[Test]
    public function an_id_bin_semitexa_did_not_derive_is_not_registered_on_its_behalf(): void
    {
        $registry = new AgentRegistry($this->root);
        putenv(AgentRegistry::IMPLICIT_ENV . '=claude');
        foreach (['codex-a339fb', 'claude-a339fb-x', 'claude-ended'] as $id) {
            putenv(AgentRegistry::ENV . '=' . $id);
            self::assertNull($registry->current(), "'{$id}' is not the shape bin/semitexa derives for claude");
        }
        putenv(AgentRegistry::IMPLICIT_ENV);
        putenv(AgentRegistry::ENV . '=claude-a339fb');
        self::assertNull($registry->current(), 'an exported id of a missing session stays missing');
        self::assertSame([], $registry->all());
    }

    #[Test]
    public function an_agents_md_the_framework_moved_past_or_that_was_edited_is_named(): void
    {
        // The portal's AGENTS.md was five months old and lacked "Say you are
        // here": nothing ever updated it (2026-10-17).
        $manifest = $this->root . '/manifest.json';
        file_put_contents($manifest, json_encode(['files' => [[
            'path' => 'AGENTS.md',
            'current_sha256' => hash('sha256', "new manual\n"),
            'previous_sha256' => [hash('sha256', "old manual\n")],
        ]]]));
        $docs = new AgentDocsFreshness($this->root, $manifest);

        self::assertSame(AgentDocsFreshness::MISSING, $docs->agentsMd());
        foreach (["new manual\n" => AgentDocsFreshness::CURRENT, "old manual\n" => AgentDocsFreshness::BEHIND, "old manual, edited\n" => AgentDocsFreshness::EDITED] as $content => $state) {
            file_put_contents($this->root . '/AGENTS.md', $content);
            self::assertSame($state, $docs->agentsMd());
        }
        self::assertSame(AgentDocsFreshness::UNKNOWN, (new AgentDocsFreshness($this->root, $this->root . '/none.json'))->agentsMd(), 'no manifest, nothing to say');
    }

    #[Test]
    public function the_projects_own_store_is_still_read_and_the_shared_one_is_owner_only(): void
    {
        $cwd = getcwd();
        $project = $this->root . '/app';
        $shared = $this->root . '/home/agents';
        mkdir($project . '/src/modules', 0o755, true);
        file_put_contents($project . '/composer.json', '{}');
        // A session from before the machine-wide store, and a record there that names no project.
        $old = (new AgentRegistry($project))->join('codex', 'joined before the shared store');
        $legacy = $project . '/' . AgentRegistry::SUBDIR . '/' . $old->id . '.json';
        $row = json_decode((string) file_get_contents($legacy), true);
        unset($row['project'], $row['task_project'], $row['joined']);
        file_put_contents($legacy, json_encode($row));
        mkdir($shared, 0o700, true);
        file_put_contents($shared . '/codex-ffffff.json', json_encode(['id' => 'codex-ffffff', 'agent' => 'codex', 'intent' => 'no project named', 'beat_at' => gmdate('c'), 'started_at' => gmdate('c'), 'ended_at' => null]));
        putenv(AgentRegistry::DIR_ENV . '=' . $shared);
        putenv(AgentRegistry::HOST_ROOT_ENV . '=/host/app');
        try {
            chdir($project);
            ProjectRoot::reset();
            $registry = new AgentRegistry($project);

            self::assertSame('/host/app', $registry->get($old->id)?->project, 'a legacy session is still this project\'s');
            self::assertEqualsCanonicalizing([$old->id, 'codex-ffffff'], array_map(static fn (AgentSession $s): string => $s->id, $registry->all()));
            self::assertSame([$old->id], array_map(static fn (AgentSession $s): string => $s->id, $registry->ofThisProject($registry->all())), 'a project-less record in the shared store is nobody\'s');

            $mine = $registry->join('claude', 'joining into the shared store');
            self::assertSame(0o600, fileperms($shared . '/' . $mine->id . '.json') & 0o777);

            symlink($this->root . '/elsewhere', $shared . '/.lock');
            $this->expectException(\RuntimeException::class);
            $registry->locked(static fn (): null => null);
        } finally {
            putenv(AgentRegistry::DIR_ENV);
            putenv(AgentRegistry::HOST_ROOT_ENV);
            if ($cwd !== false) {
                chdir($cwd);
            }
            ProjectRoot::reset();
        }
    }

    #[Test]
    public function a_repo_is_claimed_by_host_path_across_projects(): void
    {
        $repo = $this->root . '/packages/semitexa-dev';
        mkdir($repo, 0o755, true);
        exec('git -C ' . escapeshellarg($repo) . ' init -q 2>&1');
        file_put_contents($repo . '/x.php', '<?php');
        $at = static fn (string $project, array $repos): AgentSession => new AgentSession('claude-' . substr(md5($project . implode($repos)), 0, 6), 'claude', 'editing', null, $repos, gmdate('c'), gmdate('c'), null, $project);

        $visitor = $at('/h/portal', ['../dev/packages/semitexa-dev']);
        $samePath = $at('/h/portal', ['packages/semitexa-dev']);
        $local = $at('/h/dev', ['packages/semitexa-dev']);

        $claimed = (new WorkspaceActivity($this->root))->dirtyRepos([$visitor, $samePath, $local], null, '/h/dev')[0]['claimed_by'];
        self::assertEqualsCanonicalizing([$visitor->id, $local->id], $claimed, "the portal's own packages/semitexa-dev is not this one");
    }

    #[Test]
    public function an_agent_hears_on_stderr_what_the_others_changed_in_the_backlog_since_its_last_command(): void
    {
        // A portal session opened an epic in this backlog about a defect it had
        // found; the agent working here heard of it from the operator (2026-10-17).
        $registry = new AgentRegistry($this->root);
        $me = $registry->join('claude', 'working this backlog');
        $portal = (new AgentRegistry($this->root . '/portal', $this->root . '/' . AgentRegistry::SUBDIR))->join('claude', 'releasing llms.txt');
        $feed = new BacklogActivity($this->root);
        $file = $this->root . '/' . BacklogActivity::FILE;

        $line = static fn (string $at, ?string $by, string $action, string $id): string => json_encode(['at' => $at, 'by' => $by, 'action' => $action, 'id' => $id, 'summary' => 's']) . "\n";
        file_put_contents($file, $line('2026-10-17T05:00:00+00:00', $portal->id, 'started epic', 'ep-before'));
        $since = '2026-10-17T05:05:00+00:00';
        file_put_contents($file, $line('2026-10-17T05:08:58+00:00', $portal->id, 'started epic', 'ep-llms-txt-site-contributors'), FILE_APPEND);
        file_put_contents($file, $line('2026-10-17T05:09:00+00:00', $me->id, 'moved task', 'mine'), FILE_APPEND);
        file_put_contents($file, $line('2026-10-17T05:09:01+00:00', null, 'noted on task', 'by-a-human'), FILE_APPEND);

        self::assertSame(['ep-llms-txt-site-contributors', 'by-a-human'], array_column($feed->since($since, $me->id), 'id'), 'newer than my last command, and not mine');

        putenv(AgentRegistry::ENV . '=' . $me->id);
        $feed->record('started task', 'tk-new', "a   title\nover lines");
        $recorded = json_decode((string) array_slice(file($file) ?: [], -1)[0], true);
        self::assertSame([$me->id, 'a title over lines'], [$recorded['by'], $recorded['summary']]);

        // Rewind my last beat so the portal epic is news, then announce.
        $path = $this->root . '/' . AgentRegistry::SUBDIR . '/' . $me->id . '.json';
        $row = json_decode((string) file_get_contents($path), true);
        $row['beat_at'] = $since;
        file_put_contents($path, json_encode($row));
        [$stdout, $stderr] = [fopen('php://memory', 'w+'), fopen('php://memory', 'w+')];
        $output = $this->consoleOutput($stdout, $stderr);
        BacklogActivity::announce($output, $registry, $this->root);
        rewind($stdout);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        self::assertSame('', (string) stream_get_contents($stdout), 'never on stdout: JSON callers parse it');
        self::assertStringContainsString('started epic ep-llms-txt-site-contributors', $err);
        self::assertStringContainsString($portal->id . ' (portal)', $err);
        self::assertStringNotContainsString('mine', $err);
        self::assertNotSame($since, $registry->get($me->id)?->beatAt, 'and the agent is marked alive, so the same news is not told twice');
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    private function consoleOutput($stdout, $stderr): ConsoleOutputInterface
    {
        return new class ($stdout, new StreamOutput($stderr)) extends StreamOutput implements ConsoleOutputInterface {
            /** @param resource $stream */
            public function __construct($stream, private OutputInterface $error)
            {
                parent::__construct($stream);
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->error;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->error = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new \LogicException('not used');
            }
        };
    }

    #[Test]
    public function the_backlog_is_echoed_where_it_is_written_whoever_writes_it(): void
    {
        $cwd = getcwd();
        mkdir($this->root . '/src/modules', 0o755, true);
        file_put_contents($this->root . '/composer.json', '{}');
        try {
            chdir($this->root);
            ProjectRoot::reset();
            $store = new TaskStore();
            $task = new Task('tk-a', 'ep-a', 'a task', TaskStatus::NEW, 'fix_bug', 'low', [], null, 'tk-a', gmdate('c'), gmdate('c'));
            $store->save($task);
            $store->save($task->with(status: TaskStatus::IN_PROGRESS, nextStep: 'look at the handler'));
            $store->save($task->with(status: TaskStatus::IN_PROGRESS, nextStep: 'look at the handler', updatedAt: gmdate('c')));
            $traces = new TraceStore();
            $traces->openOrCreate('tk-a');
            $traces->append('tk-a', TraceEventKind::NOTE, "note on task 'tk-a'", ['task_id' => 'tk-a', 'note' => 'the defect is in the renderer']);
            $traces->append('tk-a', TraceEventKind::VERIFY_RESULT, 'verify passed');

            $rows = (new BacklogActivity($this->root))->since('2000-01-01T00:00:00+00:00', null);
            self::assertSame(['started task', 'moved task', 'noted on task'], array_column($rows, 'action'), 'a save that moved nothing, and a verify, are not news');
            self::assertSame('→ in_progress: look at the handler', $rows[1]['summary']);
            self::assertSame('the defect is in the renderer', $rows[2]['summary']);
        } finally {
            if ($cwd !== false) {
                chdir($cwd);
            }
            ProjectRoot::reset();
        }
    }

    #[Test]
    public function the_feed_is_read_by_cursor_so_nothing_in_the_same_second_is_lost_or_told_twice(): void
    {
        $registry = new AgentRegistry($this->root);
        $me = $registry->join('claude', 'reading the feed by cursor');
        $feed = new BacklogActivity($this->root);
        putenv(AgentRegistry::ENV . '=codex-abc123');
        $feed->record('started task', 'tk-1', 'one');

        $first = $feed->unread($registry->get($me->id) ?? self::fail('session'));
        self::assertSame(['tk-1'], array_column($first ?? [], 'id'), 'joined before it, so it is news');
        self::assertSame([], $feed->unread($me), 'and told once');

        // Same second as the read above: a time cursor dropped this.
        $feed->record('noted on task', 'tk-1', 'two');
        self::assertSame(['tk-1'], array_column($feed->unread($me) ?? [], 'id'));

        // A first read is not limited to the tail: what came after the previous command is all there.
        $late = $registry->join('codex', 'a session that has not read the feed yet');
        file_put_contents($this->root . '/' . BacklogActivity::FILE, str_repeat(json_encode(['at' => gmdate('c'), 'by' => 'claude-ffffff', 'action' => 'noted on task', 'id' => 'tk-bulk', 'summary' => str_repeat('x', 150)]) . "\n", 500), FILE_APPEND);
        // 500 bulk lines and both earlier changes: same second as the join, which is included.
        self::assertCount(502, $feed->unread($late) ?? [], 'past the 64 KiB tail, nothing cut off');
        self::assertCount(500, $feed->unread($me) ?? []);

        // A line still being written is left for the next read.
        file_put_contents($this->root . '/' . BacklogActivity::FILE, '{"at":"2026-10-17T07:00:00+00:00","by":"codex-abc123","action":"moved', FILE_APPEND);
        self::assertSame([], $feed->unread($me));
        file_put_contents($this->root . '/' . BacklogActivity::FILE, ' task","id":"tk-2","summary":"x"}' . "\n", FILE_APPEND);
        self::assertSame(['tk-2'], array_column($feed->unread($me) ?? [], 'id'));
    }

    #[Test]
    public function a_task_moved_to_another_epic_is_news(): void
    {
        $feed = new BacklogActivity($this->root);
        $task = new Task('tk-a', 'ep-a', 'a task', TaskStatus::NEW, 'fix_bug', 'low', [], null, 'tk-a', gmdate('c'), gmdate('c'));
        $feed->taskSaved($task, $task->with(epicId: 'ep-b'));

        $rows = $feed->since('2000-01-01T00:00:00+00:00', null);
        self::assertSame([['moved task', 'from epic ep-a to epic ep-b']], array_map(static fn (array $r): array => [$r['action'], $r['summary']], $rows));
    }
}
