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

        return is_string($id) && $id !== '' ? $this->get($id) : null;
    }

    /**
     * Mark the current session alive (and, when given, holding a task).
     * Never throws: presence must not break the command that reported it.
     */
    public function beat(?string $task = null): void
    {
        try {
            $session = $this->current();
            if ($session !== null && $session->endedAt === null) {
                $this->save($session->with(task: $task, beatAt: gmdate('c')));
            }
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
        $session = $this->get($id);
        if ($session === null) {
            return null;
        }
        $ended = $session->with(endedAt: gmdate('c'));
        $this->save($ended);

        return $ended;
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

    private function save(AgentSession $session): void
    {
        JsonFile::writeAtomic($this->path($session->id), $session->toArray());
    }

    private function path(string $id): string
    {
        return $this->projectRoot . '/' . self::SUBDIR . '/' . $id . '.json';
    }
}
