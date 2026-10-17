<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Presence;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Work\JsonFile;

/**
 * Who is working on this machine right now.
 *
 * Several agents — Claude, Codex, anyone — share one checkout, one backlog and
 * one dev stack. Before this they could only infer each other: dirty files
 * nobody here wrote, a stack restarted under them, a task marked in progress
 * two days ago by a session long gone. Each agent now joins with a name and an
 * intent, every ai:* command it runs is a heartbeat, and any agent can list
 * the others.
 *
 * One JSON file per session, written atomically. bin/semitexa mounts one
 * store for every project on the machine (~/.semitexa/agents, beside the
 * router registry) and names it in SEMITEXA_AGENTS_DIR: an agent started in a
 * consumer project edits the workspace's packages and backlog too, and a
 * per-project store hid it from everyone there (2026-10-17). Without the mount
 * the store is the project's own var/ai-work/agents. Each session names its
 * project: a task id or a repo path means something only in the project it
 * came from, so claims and repo ownership never cross projects.
 *
 * A session nobody ended simply goes quiet and stops counting as live after
 * {@see AgentSession::LIVE_SECONDS}.
 */
final class AgentRegistry
{
    public const SUBDIR = 'var/ai-work/agents';
    public const ENV = 'SEMITEXA_AGENT_SESSION';
    /** The machine-wide store mounted into the container, set by docker-compose.yml. */
    public const DIR_ENV = 'SEMITEXA_AGENTS_DIR';
    /** The project's path on the host: inside the container every project is /var/www/html. */
    public const HOST_ROOT_ENV = 'SEMITEXA_HOST_PROJECT_ROOT';
    /**
     * Set by bin/semitexa beside a session id it derived itself (Claude Code's
     * session id, an agent CLI ancestor) for an agent that exported none: the
     * agent's name, and leave to register that session on first use.
     */
    public const IMPLICIT_ENV = 'SEMITEXA_AGENT_IMPLICIT';
    public const UNJOINED_INTENT = 'not joined: said nothing about what it is doing';

    private readonly string $dir;
    private readonly string $project;
    /** The project's own store, still read while the machine-wide one is used: sessions from before it. */
    private readonly ?string $legacyDir;

    /**
     * @param string|null $dir the store; by default the machine-wide one when this is
     *        the project the process runs in, else (a scratch root: tests, fixtures) its own
     */
    public function __construct(string $projectRoot, ?string $dir = null)
    {
        $runsHere = self::isProcessRoot($projectRoot);
        $shared = $runsHere ? self::env(self::DIR_ENV) : null;
        $own = $projectRoot . '/' . self::SUBDIR;
        $this->dir = rtrim($dir ?? $shared ?? $own, '/');
        $this->project = ($runsHere ? self::env(self::HOST_ROOT_ENV) : null) ?? $projectRoot;
        $this->legacyDir = $dir === null && $shared !== null && rtrim($shared, '/') !== $own ? $own : null;
    }

    /** The project this registry speaks for, as sessions record it. */
    public function project(): string
    {
        return $this->project;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Sessions of this registry's project only: what a task id or a repo path
     * may be matched against.
     *
     * @param list<AgentSession> $sessions
     * @return list<AgentSession>
     */
    public function ofThisProject(array $sessions): array
    {
        return array_values(array_filter($sessions, fn (AgentSession $s): bool => $s->project === $this->project));
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
        // An agent bin/semitexa already recognises keeps its id: joining names
        // the session every one of its commands reports to, no export needed.
        $implicit = $this->implicitId();
        $before = $implicit !== null ? $this->get($implicit) : null;
        $ongoing = $before !== null && $before->endedAt === null ? $before : null;
        $session = new AgentSession(
            $implicit ?? $agent . '-' . bin2hex(random_bytes(3)),
            $agent,
            $intent,
            $ongoing?->task,
            array_values(array_unique($repos)),
            $ongoing->startedAt ?? $now,
            $now,
            null,
            $this->project,
            $ongoing?->taskProject,
        );
        $this->save($session);

        return $session;
    }

    /** Whether the id this process reports to was derived by bin/semitexa, not exported. */
    public function isImplicit(): bool
    {
        return $this->implicitId() !== null;
    }

    public function get(string $id): ?AgentSession
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $id) !== 1) {
            return null;
        }
        foreach ($this->stores() as $store => $owner) {
            $file = $store . '/' . $id . '.json';
            if (!is_file($file)) {
                continue;
            }
            try {
                return AgentSession::fromArray(JsonFile::read($file), $owner);
            } catch (\RuntimeException) {
                return null;
            }
        }

        return null;
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
        if ($session !== null && $session->endedAt === null) {
            return $session;
        }

        // An agent bin/semitexa recognised but that never joined, or that left
        // and is working again: it shows up from its first command.
        $implicit = $this->implicitId();
        if ($implicit === null) {
            return null;
        }
        $now = gmdate('c');
        $agent = (string) self::env(self::IMPLICIT_ENV);
        $session = new AgentSession($implicit, $agent, self::UNJOINED_INTENT, null, [], $now, $now, null, $this->project, null, false);
        try {
            $this->save($session);
        } catch (\RuntimeException) {
            return null;
        }

        return $session;
    }

    /**
     * Record that $session holds $task. Unlike beat() this throws: a claim
     * that was not written must not be reported as taken.
     */
    public function recordTask(AgentSession $session, string $task): void
    {
        $this->save($session->with(task: $task, beatAt: gmdate('c'), taskProject: $this->project));
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
                // Only a task of this project's backlog can have been taken over here.
                $held = $task ?? ($session->task !== null && $session->holds($session->task, $this->project) ? $session->task : null);
                $lost = $held !== null && $this->holderOf($held, $session->id) !== null;
                $this->save($lost
                    ? $session->with(clearTask: true, beatAt: gmdate('c'))
                    : $session->with(task: $task, beatAt: gmdate('c'), taskProject: $task !== null ? $this->project : null));
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
        $byId = [];
        foreach ($this->stores() as $store => $owner) {
            foreach (glob($store . '/*.json') ?: [] as $file) {
                try {
                    $session = AgentSession::fromArray(JsonFile::read($file), $owner);
                } catch (\RuntimeException) {
                    continue;
                }
                // A legacy session saved since then lives in both stores: the newer counts.
                if (isset($byId[$session->id]) && strcmp($byId[$session->id]->beatAt, $session->beatAt) >= 0) {
                    continue;
                }
                $byId[$session->id] = $session;
            }
        }
        $out = array_values(array_filter($byId, static fn (AgentSession $s): bool => $includeGone || $s->isLive($now)));
        usort($out, static fn (AgentSession $a, AgentSession $b): int => strcmp($b->beatAt, $a->beatAt));

        return $out;
    }

    /**
     * The live session other than $selfId that holds $task of this project's
     * backlog, wherever that session joined.
     */
    public function holderOf(string $task, ?string $selfId, ?int $now = null): ?AgentSession
    {
        foreach ($this->all(false, $now) as $session) {
            if ($session->holds($task, $this->project) && $session->id !== $selfId) {
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
        $dir = $this->dir;
        if (!is_dir($dir) && !@mkdir($dir, $this->isShared() ? 0o700 : 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        $lock = $dir . '/.lock';
        $this->refuseLink($lock);
        $handle = @fopen($lock, 'c');
        if (!$this->isShared()) {
            @chmod($lock, 0o666);
        }
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
        //
        // Not the machine-wide store: it sits in the user's home, the app runs
        // as the host user, and another local user must not forge, end or take
        // over sessions there — owner only, and never through a planted link.
        $dir = $this->dir;
        $shared = $this->isShared();
        if (!is_dir($dir) && !@mkdir($dir, $shared ? 0o700 : 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        $file = $this->path($session->id);
        $this->refuseLink($file);
        if (!$shared) {
            @chmod($dir, 0o777);
            JsonFile::writeAtomic($file, $session->toArray());
            @chmod($file, 0o666);

            return;
        }
        // Owner only from the first byte: a chmod after the rename left the
        // session readable by others for a moment, and a store that existed
        // with wider permissions was never narrowed.
        @chmod($dir, 0o700);
        $mask = umask(0o077);
        try {
            JsonFile::writeAtomic($file, $session->toArray());
        } finally {
            umask($mask);
        }
        @chmod($file, 0o600);
    }

    /**
     * Where sessions are read from, with the project a record that names none
     * belongs to: in the machine-wide store nobody's (every project would
     * otherwise claim it), in the project's own store this project's.
     *
     * @return array<string, string>
     */
    private function stores(): array
    {
        if (!$this->isShared()) {
            return [$this->dir => $this->project];
        }

        return [$this->dir => ''] + ($this->legacyDir !== null ? [$this->legacyDir => $this->project] : []);
    }

    private function isShared(): bool
    {
        return $this->legacyDir !== null;
    }

    private function refuseLink(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException("{$path} is a symbolic link; refusing to write presence through it");
        }
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    /**
     * The exported session id when bin/semitexa derived it: shaped
     * <agent>-<6 hex>, with the agent named in SEMITEXA_AGENT_IMPLICIT.
     */
    private function implicitId(): ?string
    {
        $agent = self::env(self::IMPLICIT_ENV);
        $id = self::env(self::ENV);
        if ($agent === null || $id === null || preg_match('/^[a-z][a-z0-9-]{0,15}$/', $agent) !== 1) {
            return null;
        }

        return preg_match('/^' . preg_quote($agent, '/') . '-[0-9a-f]{6}$/', $id) === 1 ? $id : null;
    }

    private static function isProcessRoot(string $projectRoot): bool
    {
        $mine = realpath(ProjectRoot::get());

        return $mine !== false && $mine === realpath($projectRoot);
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
