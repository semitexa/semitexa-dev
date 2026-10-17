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
    /**
     * How far each session has read the feed: a byte offset, not a time. A time
     * at second precision dropped a change made in the same second as the
     * reader's previous command, and moved on even when the feed could not be read.
     */
    public const CURSORS = 'var/ai-work/activity.cursors';
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
        } elseif ($before->epicId !== $after->epicId) {
            $this->record('moved task', $after->id, "from epic {$before->epicId} to epic {$after->epicId}");
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
     * Changes after $since by anyone but $selfId, oldest first (a time-based
     * view, for a reader that has no cursor yet).
     *
     * @return list<array{at: string, by: ?string, action: string, id: string, summary: string}>
     */
    public function since(string $since, ?string $selfId): array
    {
        $read = $this->readFrom(null);
        $after = strtotime($since);

        return array_values(array_filter(
            $read === null ? [] : $read['rows'],
            static fn (array $r): bool => strtotime($r['at']) > $after && ($r['by'] === null || $r['by'] !== $selfId),
        ));
    }

    /**
     * What the others wrote after this session last read the feed, and moves
     * its cursor past it. Null when the feed could not be read: the cursor
     * stays, so nothing is skipped.
     *
     * @return list<array{at: string, by: ?string, action: string, id: string, summary: string}>|null
     */
    public function unread(AgentSession $me): ?array
    {
        $cursorFile = $this->projectRoot . '/' . self::CURSORS . '/' . $me->id;
        $cursor = is_file($cursorFile) ? (int) @file_get_contents($cursorFile) : null;
        // The first read starts at the beginning: a tail could cut off what came
        // after the session's previous command.
        $read = $this->readFrom($cursor ?? 0);
        if ($read === null) {
            return null;
        }
        // Written by root (a running container) and by the host user (a one-off
        // run) alike, like the project's own presence store. A cursor that could
        // not move would tell the same news on every command: say nothing instead.
        $dir = dirname($cursorFile);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return null;
        }
        @chmod($dir, 0o777);
        if (@file_put_contents($cursorFile, (string) $read['end'], LOCK_EX) === false) {
            return null;
        }
        @chmod($cursorFile, 0o666);
        $rows = $read['rows'];
        if ($cursor === null) {
            // First read for this session: what happened since its previous command,
            // that second included — told twice beats lost.
            $from = strtotime($me->beatAt);
            $rows = array_filter($rows, static fn (array $r): bool => strtotime($r['at']) >= $from);
        }

        return array_values(array_filter($rows, static fn (array $r): bool => $r['by'] === null || $r['by'] !== $me->id));
    }

    /**
     * Mark the current agent alive and tell it, on stderr, what the others
     * changed since it last heard. Never throws.
     */
    public static function announce(OutputInterface $output, AgentRegistry $registry, string $projectRoot): void
    {
        try {
            $me = $registry->current();
            $registry->beat();
            if ($me === null || !$output instanceof ConsoleOutputInterface) {
                return;
            }
            $news = (new self($projectRoot))->unread($me);
            if ($news === null || $news === []) {
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

    /**
     * Complete lines from byte $from (null: the tail) and the offset after the
     * last of them; a line still being written is left for the next read.
     *
     * @return array{rows: list<array{at: string, by: ?string, action: string, id: string, summary: string}>, end: int}|null
     */
    private function readFrom(?int $from): ?array
    {
        $file = $this->projectRoot . '/' . self::FILE;
        if (!file_exists($file)) {
            return ['rows' => [], 'end' => 0];
        }
        $size = @filesize($file);
        $handle = @fopen($file, 'r');
        if ($size === false || $handle === false) {
            return null;
        }
        try {
            // A cursor past the end means the feed was replaced: start over.
            $tail = $from === null || $from > $size;
            $start = $tail ? max(0, $size - self::TAIL_BYTES) : $from;
            if (fseek($handle, $start) !== 0) {
                return null;
            }
            $chunk = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        if ($chunk === false) {
            return null;
        }
        $complete = strrpos($chunk, "\n");
        $end = $complete === false ? $start : $start + $complete + 1;
        $lines = $complete === false ? [] : explode("\n", substr($chunk, 0, $complete));
        if ($tail && $start > 0) {
            array_shift($lines); // the tail starts mid-line
        }

        $rows = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !is_string($row['at'] ?? null)) {
                continue;
            }
            $rows[] = [
                'at' => $row['at'],
                'by' => is_string($row['by'] ?? null) ? $row['by'] : null,
                'action' => (string) ($row['action'] ?? ''),
                'id' => (string) ($row['id'] ?? ''),
                'summary' => (string) ($row['summary'] ?? ''),
            ];
        }

        return ['rows' => $rows, 'end' => $end];
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
