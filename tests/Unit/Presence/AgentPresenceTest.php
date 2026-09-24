<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Presence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentRegistry;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentSession;
use Semitexa\Dev\Application\Service\Ai\Presence\TaskClaim;
use Semitexa\Dev\Application\Service\Ai\Presence\WorkspaceActivity;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

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

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-presence-' . uniqid();
        mkdir($this->root, 0o755, true);
        $this->envBefore = getenv(AgentRegistry::ENV);
    }

    protected function tearDown(): void
    {
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
}
