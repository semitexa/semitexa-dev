<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Presence;

use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;

/**
 * Who is working in this workspace right now.
 *
 * Several agents — Claude, Codex, anyone — share one checkout, one backlog and
 * one dev stack. Before this they could only infer each other: dirty files
 * nobody here wrote, a stack restarted under them, a task marked in progress
 * two days ago by a session long gone. Each agent now joins with a name and an
 * intent, every ai:* command it runs is a heartbeat, and any agent can list
 * the others.
 *
 * One JSON file per session under var/ai-work/agents/, beside the backlog it
 * describes, written atomically. A session nobody ended simply goes quiet and
 * stops counting as live after {@see AgentSession::LIVE_SECONDS}.
 */
final class AgentRegistry
{
    public const SUBDIR = 'var/ai-work/agents';
    public const ENV = 'SEMITEXA_AGENT_SESSION';

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @param list<string> $repos
     */
    public function join(string $agent, string $intent, array $repos = []): AgentSession
    {
        $agent = strtolower(trim($agent));
        if (preg_match('/^[a-z][a-z0-9-]{0,23}$/', $agent) !== 1) {
            throw new \InvalidArgumentException("--name must be a short lowercase agent name (claude, codex, copilot, ...), got '{$agent}'");
        }
        $intent = trim($intent);
        if (mb_strlen($intent) < 10) {
            throw new \InvalidArgumentException('--intent must say in a sentence what you are about to do — it is what the other agents read');
        }

        $now = gmdate('c');
        $session = new AgentSession($agent . '-' . bin2hex(random_bytes(3)), $agent, $intent, null, array_values(array_unique($repos)), $now, $now);
        $this->save($session);

        return $session;
    }

    public function get(string $id): ?AgentSession
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $id) !== 1 || !is_file($this->path($id))) {
            return null;
        }
        try {
            return AgentSession::fromArray(JsonFile::read($this->path($id)));
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * The session this process belongs to, from SEMITEXA_AGENT_SESSION.
     */
    public function current(): ?AgentSession
    {
        $id = getenv(self::ENV);
        $session = is_string($id) && $id !== '' ? $this->get($id) : null;

        // An ended session is not "you" any more: after `ai:agent leave` it
        // would otherwise still answer beats and claims it can no longer hold.
        return $session !== null && $session->endedAt === null ? $session : null;
    }

    /**
     * Record that $session holds $task. Unlike beat() this throws: a claim
     * that was not written must not be reported as taken.
     */
    public function recordTask(AgentSession $session, string $task): void
    {
        $this->save($session->with(task: $task, beatAt: gmdate('c')));
    }

    /**
     * Mark the current session alive (and, when given, holding a task).
     * Never throws: presence must not break the command that reported it.
     *
     * Under the registry lock, like claims and leave: a beat that read the
     * session before a take-over and saved after it would hand the task back.
     */
    public function beat(?string $task = null): void
    {
        try {
            $this->locked(function () use ($task): void {
                $session = $this->current();
                if ($session === null) {
                    return;
                }
                // A session that went quiet may have had its task taken over
                // meanwhile; beating again must not make it a second holder.
                $held = $task ?? $session->task;
                $lost = $held !== null && $this->holderOf($held, $session->id) !== null;
                $this->save($lost
                    ? $session->with(clearTask: true, beatAt: gmdate('c'))
                    : $session->with(task: $task, beatAt: gmdate('c')));
            });
        } catch (\Throwable) {
        }
    }

    public function release(string $id): void
    {
        $session = $this->get($id);
        if ($session !== null && $session->task !== null) {
            $this->save($session->with(clearTask: true, beatAt: gmdate('c')));
        }
    }

    public function leave(string $id): ?AgentSession
    {
        return $this->locked(function () use ($id): ?AgentSession {
            $session = $this->get($id);
            if ($session === null) {
                return null;
            }
            $ended = $session->with(endedAt: gmdate('c'));
            $this->save($ended);

            return $ended;
        });
    }

    /**
     * Every session that is live, newest heartbeat first; ended and silent
     * ones only when asked for.
     *
     * @return list<AgentSession>
     */
    public function all(bool $includeGone = false, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach (glob($this->projectRoot . '/' . self::SUBDIR . '/*.json') ?: [] as $file) {
            try {
                $session = AgentSession::fromArray(JsonFile::read($file));
            } catch (\RuntimeException) {
                continue;
            }
            if ($includeGone || $session->isLive($now)) {
                $out[] = $session;
            }
        }
        usort($out, static fn (AgentSession $a, AgentSession $b): int => strcmp($b->beatAt, $a->beatAt));

        return $out;
    }

    /**
     * The live session other than $selfId that holds $task, if any.
     */
    public function holderOf(string $task, ?string $selfId, ?int $now = null): ?AgentSession
    {
        foreach ($this->all(false, $now) as $session) {
            if ($session->task === $task && $session->id !== $selfId) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Run a check-then-write on the registry as one step, so two agents
     * claiming the same task at the same moment cannot both win.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function locked(callable $fn): mixed
    {
        $dir = $this->projectRoot . '/' . self::SUBDIR;
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        $handle = @fopen($dir . '/.lock', 'c');
        @chmod($dir . '/.lock', 0o666);
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException("cannot lock {$dir}");
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function save(AgentSession $session): void
    {
        // Shared by everyone who runs ai:* here — bin/semitexa enters a running
        // container as root, a one-off container runs as the host user — so a
        // directory created by one must stay writable by the other, or the
        // second agent's heartbeats and claims fail silently.
        $dir = $this->projectRoot . '/' . self::SUBDIR;
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        @chmod($dir, 0o777);
        JsonFile::writeAtomic($this->path($session->id), $session->toArray());
        @chmod($this->path($session->id), 0o666);
    }

    private function path(string $id): string
    {
        return $this->projectRoot . '/' . self::SUBDIR . '/' . $id . '.json';
    }
}
