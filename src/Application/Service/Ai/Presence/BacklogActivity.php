<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Presence;

use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What changed in this project's backlog, and who changed it.
 *
 * Another agent's epic, task or note reached an agent only when it happened to
 * run ai:orient: a session in semitexa.portal opened an epic in the workspace's
 * backlog about a defect it had found, and the agent working there learned of
 * it from the operator (2026-10-17). Every backlog change now appends one line
 * to var/ai-work/activity.ndjson — written where the backlog is written (the
 * task and epic stores, and the trace store for notes), so no command can
 * change the backlog without it — and every ai:* command that marks its agent
 * alive says, on stderr, what the others changed since that agent's previous
 * command — a few lines, never the JSON on stdout.
 */
final class BacklogActivity
{
    public const FILE = 'var/ai-work/activity.ndjson';
    private const SHOWN = 3;
    /** Read from the end only: the feed only ever grows. */
    private const TAIL_BYTES = 65536;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * Never throws: a backlog change that was saved must not fail on its echo.
     */
    public function record(string $action, string $id, string $summary): void
    {
        $by = getenv(AgentRegistry::ENV);
        $line = json_encode([
            'at' => gmdate('c'),
            'by' => is_string($by) && preg_match('/^[a-z0-9-]{1,40}$/', $by) === 1 ? $by : null,
            'action' => $action,
            'id' => $id,
            'summary' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $summary)), 0, 160),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $file = $this->projectRoot . '/' . self::FILE;
        if ($line === false || (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0o777, true))) {
            return;
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** A task was written: said only when it is new or its status, title or next step moved. */
    public function taskSaved(?Task $before, Task $after): void
    {
        if ($before === null) {
            $this->record('started task', $after->id, $after->title . " (epic {$after->epicId})");
        } elseif ($before->status !== $after->status) {
            $this->record('moved task', $after->id, '→ ' . $after->status->value . ($after->nextStep !== null ? ': ' . $after->nextStep : ''));
        } elseif ($before->nextStep !== $after->nextStep || $before->title !== $after->title) {
            $this->record('updated task', $after->id, $after->nextStep ?? $after->title);
        }
    }

    /** An epic was written: said only when it is new or its status, title or goal moved (not a task count). */
    public function epicSaved(?Epic $before, Epic $after): void
    {
        if ($before === null) {
            $this->record('started epic', $after->id, $after->title . ($after->goal !== '' ? ': ' . $after->goal : ''));
        } elseif ($before->status !== $after->status) {
            $this->record('moved epic', $after->id, '→ ' . $after->status->value . ' ' . $after->title);
        } elseif ($before->title !== $after->title || $before->goal !== $after->goal) {
            $this->record('updated epic', $after->id, $after->title);
        }
    }

    /**
     * Changes after $since by anyone but $selfId, oldest first.
     *
     * @return list<array{at: string, by: ?string, action: string, id: string, summary: string}>
     */
    public function since(string $since, ?string $selfId): array
    {
        $file = $this->projectRoot . '/' . self::FILE;
        $size = @filesize($file);
        if ($size === false || $size === 0) {
            return [];
        }
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [];
        }
        try {
            $offset = max(0, $size - self::TAIL_BYTES);
            fseek($handle, $offset);
            $chunk = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $lines = explode("\n", $chunk);
        if ($offset > 0) {
            array_shift($lines); // cut mid-line
        }

        $out = [];
        $after = strtotime($since);
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !is_string($row['at'] ?? null) || strtotime($row['at']) <= $after) {
                continue;
            }
            $by = is_string($row['by'] ?? null) ? $row['by'] : null;
            if ($by !== null && $by === $selfId) {
                continue;
            }
            $out[] = ['at' => $row['at'], 'by' => $by, 'action' => (string) ($row['action'] ?? ''), 'id' => (string) ($row['id'] ?? ''), 'summary' => (string) ($row['summary'] ?? '')];
        }

        return $out;
    }

    /**
     * Mark the current agent alive and tell it, on stderr, what the others
     * changed since its previous command. Never throws.
     */
    public static function announce(OutputInterface $output, AgentRegistry $registry, string $projectRoot): void
    {
        try {
            $me = $registry->current();
            $registry->beat();
            if ($me === null || !$output instanceof ConsoleOutputInterface) {
                return;
            }
            $news = (new self($projectRoot))->since($me->beatAt, $me->id);
            if ($news === []) {
                return;
            }
            $err = $output->getErrorOutput();
            $err->writeln('ai: since your last command, in this backlog:');
            foreach (array_slice($news, -self::SHOWN) as $n) {
                $err->writeln(sprintf('  %s %s %s %s — %s', substr($n['at'], 11, 5), self::who($registry, $n['by']), $n['action'], $n['id'], $n['summary']));
            }
            if (count($news) > self::SHOWN) {
                $err->writeln(sprintf('  … and %d more: bin/semitexa ai:orient', count($news) - self::SHOWN));
            }
        } catch (\Throwable) {
        }
    }

    private static function who(AgentRegistry $registry, ?string $by): string
    {
        if ($by === null) {
            return 'someone who never joined';
        }
        $session = $registry->get($by);
        if ($session !== null && $session->project !== '' && $session->project !== $registry->project()) {
            return $by . ' (' . basename($session->project) . ')';
        }

        return $by;
    }
}
