<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Ai\Trace\Trace;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Trace\TraceHeader;
use Semitexa\Dev\Ai\Trace\TraceStore;
use Semitexa\Dev\Ai\Work\Epic;
use Semitexa\Dev\Ai\Work\EpicStore;
use Semitexa\Dev\Ai\Work\Task;
use Semitexa\Dev\Ai\Work\TaskStatus;
use Semitexa\Dev\Ai\Work\TaskStore;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Single-call session dashboard for an agent that just opened the repo.
 *
 * Fuses: git (branch + last commit + dirty flag) + active epic + in-progress
 * tasks + recent traces + last verify result + a next-step suggestion.
 *
 * Designed as the **first command** a cold-start agent runs. One tool call
 * should replace 5–6 probes (ai:ask project, ai:epic list, ai:work list,
 * ai:trace list, git status, git log).
 *
 * Cheap by construction — reads file-backed stores and runs two short shell
 * commands for git. Does NOT execute linters, verification, or graph rebuild.
 */
#[AsCommand(
    name: 'ai:orient',
    description: 'Session dashboard: git + active epic + in-progress tasks + recent traces + next step. Run this first on a cold start.',
)]
final class AiOrientCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected EpicStore $epicStore;

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    #[InjectAsReadonly]
    protected TraceStore $traceStore;

    public function __construct()
    {
        parent::__construct('ai:orient');
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the full JSON envelope (default for non-interactive callers)')
            ->addOption('human', null, InputOption::VALUE_NONE, 'Force human-readable output even in non-interactive mode')
            ->addOption('traces', null, InputOption::VALUE_REQUIRED, 'How many recent traces to include', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $traceLimit = max(1, min(50, (int) $input->getOption('traces')));

        $git           = $this->collectGit();
        $epics         = $this->collectEpics();
        $inProgress    = $this->collectInProgressTasks();
        $activeEpicId  = $this->deriveActiveEpicId($inProgress, $epics);
        $recentTraces  = $this->collectRecentTraces($traceLimit);
        $lastVerify    = $this->findLastVerify($recentTraces);
        $hints         = $this->suggestNext($git, $activeEpicId, $inProgress, $epics);

        $envelope = [
            'artifact'     => 'semitexa.ai-orient/v1',
            'generated_at' => gmdate('c'),
            'cwd'          => getcwd() ?: '',
            'git'          => $git,
            'backlog'      => [
                'total_epics'           => count($epics),
                'active_epic_id'        => $activeEpicId,
                'active_epic'           => $activeEpicId !== null ? $this->epicBrief($epics, $activeEpicId) : null,
                'in_progress_tasks'     => array_map(fn(Task $t) => $this->taskBrief($t), $inProgress),
                'in_progress_count'     => count($inProgress),
            ],
            'recent_traces'  => $recentTraces,
            'last_verify'    => $lastVerify,
            'suggest_next'   => $hints['summary'],
            'next_command'   => $hints['commands'],
        ];

        $forceJson  = (bool) $input->getOption('json');
        $forceHuman = (bool) $input->getOption('human');
        $useHuman   = $forceHuman || (!$forceJson && $input->isInteractive());

        if ($useHuman) {
            $this->renderHuman(new SymfonyStyle($input, $output), $envelope);
        } else {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        return self::SUCCESS;
    }

    // ─── collectors ────────────────────────────────────────────────

    /**
     * @return array{is_repo: bool, branch: ?string, head: ?string, last_commit_summary: ?string, dirty: ?bool, ahead: ?int, behind: ?int}
     */
    private function collectGit(): array
    {
        $cwd = getcwd() ?: '';
        $cdPrefix = 'cd ' . escapeshellarg($cwd) . ' && ';

        $isRepo = trim((string) shell_exec($cdPrefix . 'git rev-parse --is-inside-work-tree 2>/dev/null')) === 'true';

        if (!$isRepo) {
            return [
                'is_repo' => false,
                'branch'  => null,
                'head'    => null,
                'last_commit_summary' => null,
                'dirty'   => null,
                'ahead'   => null,
                'behind'  => null,
            ];
        }

        $branch = trim((string) shell_exec($cdPrefix . 'git branch --show-current 2>/dev/null')) ?: null;
        $head   = trim((string) shell_exec($cdPrefix . 'git rev-parse --short HEAD 2>/dev/null')) ?: null;
        $last   = trim((string) shell_exec($cdPrefix . 'git log -1 --pretty=%s 2>/dev/null')) ?: null;
        $status = (string) shell_exec($cdPrefix . 'git status --porcelain 2>/dev/null');
        $dirty  = trim($status) !== '';

        $ahead  = null;
        $behind = null;
        if ($branch !== null) {
            $track = trim((string) shell_exec($cdPrefix . 'git rev-list --left-right --count @{u}...HEAD 2>/dev/null'));
            if ($track !== '' && preg_match('/^(\d+)\s+(\d+)$/', $track, $m)) {
                $behind = (int) $m[1];
                $ahead  = (int) $m[2];
            }
        }

        return [
            'is_repo' => true,
            'branch'  => $branch,
            'head'    => $head,
            'last_commit_summary' => $last,
            'dirty'   => $dirty,
            'ahead'   => $ahead,
            'behind'  => $behind,
        ];
    }

    /**
     * @return list<Epic>
     */
    private function collectEpics(): array
    {
        try {
            return $this->epicStore->list();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<Task>
     */
    private function collectInProgressTasks(): array
    {
        $out = [];
        foreach ([TaskStatus::IN_PROGRESS, TaskStatus::BLOCKED] as $status) {
            try {
                foreach ($this->taskStore->list(null, $status) as $task) {
                    $out[] = $task;
                }
            } catch (\Throwable) {
                // store not initialised yet — that's fine, return what we have
            }
        }
        usort($out, static fn(Task $a, Task $b) => strcmp($b->updatedAt, $a->updatedAt));
        return $out;
    }

    /**
     * @param list<Task> $inProgress
     * @param list<Epic> $epics
     */
    private function deriveActiveEpicId(array $inProgress, array $epics): ?string
    {
        if ($inProgress !== []) {
            return $inProgress[0]->epicId;
        }
        foreach ($epics as $epic) {
            if ($epic->status->value === 'in_progress') {
                return $epic->id;
            }
        }
        return null;
    }

    /**
     * @return list<array{id: string, created_at: string, topic: ?string, recipe: ?string, last_event: ?array{kind: string, at: string, summary: string}}>
     */
    private function collectRecentTraces(int $limit): array
    {
        try {
            $headers = $this->traceStore->list();
        } catch (\Throwable) {
            return [];
        }

        usort($headers, static fn(TraceHeader $a, TraceHeader $b) => strcmp($b->createdAt, $a->createdAt));
        $headers = array_slice($headers, 0, $limit);

        $out = [];
        foreach ($headers as $h) {
            $lastEvent = null;
            try {
                $trace = $this->traceStore->read($h->traceId);
                $events = $trace->events;
                if ($events !== []) {
                    $tail = end($events);
                    $lastEvent = [
                        'kind'    => $tail->eventKind,
                        'at'      => $tail->at,
                        'summary' => $tail->summary,
                    ];
                }
            } catch (\Throwable) {
                // skip unreadable traces
            }
            $out[] = [
                'id'         => $h->traceId,
                'created_at' => $h->createdAt,
                'topic'      => $h->topic,
                'recipe'     => $h->recipe,
                'last_event' => $lastEvent,
            ];
        }
        return $out;
    }

    /**
     * @param list<array{id: string, created_at: string, topic: ?string, recipe: ?string, last_event: ?array{kind: string, at: string, summary: string}}> $recentTraces
     * @return array{trace_id: string, at: string, summary: string, verdict: ?string}|null
     */
    private function findLastVerify(array $recentTraces): ?array
    {
        foreach ($recentTraces as $row) {
            $last = $row['last_event'];
            if ($last !== null && $last['kind'] === TraceEventKind::VERIFY_RESULT) {
                return [
                    'trace_id' => $row['id'],
                    'at'       => $last['at'],
                    'summary'  => $last['summary'],
                    'verdict'  => $this->parseVerdictFromSummary($last['summary']),
                ];
            }
        }
        // scan deeper — look through trace bodies
        foreach ($recentTraces as $row) {
            try {
                $trace = $this->traceStore->read($row['id']);
                foreach (array_reverse($trace->events) as $e) {
                    if ($e->eventKind === TraceEventKind::VERIFY_RESULT) {
                        return [
                            'trace_id' => $row['id'],
                            'at'       => $e->at,
                            'summary'  => $e->summary,
                            'verdict'  => $this->parseVerdictFromSummary($e->summary),
                        ];
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    private function parseVerdictFromSummary(string $summary): ?string
    {
        $lower = strtolower($summary);
        if (str_contains($lower, 'pass')) {
            return 'pass';
        }
        if (str_contains($lower, 'fail')) {
            return 'fail';
        }
        return null;
    }

    // ─── next-step suggestion ──────────────────────────────────────

    /**
     * @param array{is_repo: bool, dirty: ?bool, ...} $git
     * @param list<Task> $inProgress
     * @param list<Epic> $epics
     * @return array{summary: string, commands: list<array{cmd: string, args: list<string>, why: string}>}
     */
    private function suggestNext(array $git, ?string $activeEpicId, array $inProgress, array $epics): array
    {
        $cmds = [];
        $parts = [];

        if ($inProgress !== []) {
            $top = $inProgress[0];
            $parts[] = "Resume task `{$top->id}` ({$top->status->value}) under epic `{$top->epicId}`.";
            $cmds[] = [
                'cmd'  => 'ai:work',
                'args' => ['resume', '--id=' . $top->id, '--json'],
                'why'  => "resume the in-progress task directly",
            ];
            if ($top->nextStep !== null && $top->nextStep !== '') {
                $parts[] = 'Next step on record: ' . $top->nextStep;
            }
        } elseif ($activeEpicId !== null) {
            $parts[] = "Epic `{$activeEpicId}` has no in-progress tasks — pick one up.";
            $cmds[] = [
                'cmd'  => 'ai:work',
                'args' => ['list', '--epic=' . $activeEpicId, '--status=new', '--json'],
                'why'  => 'list new tasks in the active epic',
            ];
        } else {
            $activeCount = 0;
            foreach ($epics as $e) {
                if (!in_array($e->status->value, ['discarded', 'archived'], true)) {
                    $activeCount++;
                }
            }
            if ($activeCount > 0) {
                $parts[] = 'No in-progress tasks. Check the backlog.';
                $cmds[] = [
                    'cmd'  => 'ai:epic',
                    'args' => ['list', '--json'],
                    'why'  => 'show active epics',
                ];
            } else {
                $parts[] = 'No active backlog. Classify the request first.';
                $cmds[] = [
                    'cmd'  => 'ai:task',
                    'args' => ['"<the request>"', '--json'],
                    'why'  => 'classify the user request into a recipe',
                ];
            }
        }

        if (($git['dirty'] ?? false) === true) {
            $parts[] = 'Working tree is dirty — verify before committing.';
            $cmds[] = [
                'cmd'  => 'ai:verify',
                'args' => ['--json'],
                'why'  => 'lint/test the current diff',
            ];
        }

        $cmds[] = [
            'cmd'  => 'ai:ask',
            'args' => ['project', '--json'],
            'why'  => 'structural overview if you need it (27 modules)',
        ];

        return [
            'summary'  => implode(' ', $parts),
            'commands' => $cmds,
        ];
    }

    // ─── formatters ────────────────────────────────────────────────

    /**
     * @param list<Epic> $epics
     * @return array{id: string, title: string, status: string, task_count: int}|null
     */
    private function epicBrief(array $epics, string $id): ?array
    {
        foreach ($epics as $e) {
            if ($e->id === $id) {
                return [
                    'id'         => $e->id,
                    'title'      => $e->title,
                    'status'     => $e->status->value,
                    'task_count' => count($e->taskIds),
                ];
            }
        }
        return null;
    }

    /**
     * @return array{id: string, epic_id: string, title: string, status: string, recipe: string, risk: string, next_step: ?string, context_refs: list<string>, updated_at: string}
     */
    private function taskBrief(Task $task): array
    {
        return [
            'id'           => $task->id,
            'epic_id'      => $task->epicId,
            'title'        => $task->title,
            'status'       => $task->status->value,
            'recipe'       => $task->recipe,
            'risk'         => $task->risk,
            'next_step'    => $task->nextStep,
            'context_refs' => $task->contextRefs,
            'updated_at'   => $task->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function renderHuman(SymfonyStyle $io, array $envelope): void
    {
        $io->title('Session dashboard (ai:orient)');

        $git = $envelope['git'];
        if ($git['is_repo']) {
            $dirty = $git['dirty'] ? 'dirty' : 'clean';
            $track = '';
            if ($git['ahead'] !== null || $git['behind'] !== null) {
                $track = " (ahead {$git['ahead']} / behind {$git['behind']})";
            }
            $io->section('Git');
            $io->writeln("  branch: {$git['branch']}{$track} — {$dirty}");
            $io->writeln("  head:   {$git['head']}  {$git['last_commit_summary']}");
        } else {
            $io->section('Git');
            $io->writeln('  (not a git repository)');
        }

        $backlog = $envelope['backlog'];
        $io->section('Backlog');
        $io->writeln("  epics: {$backlog['total_epics']} total  in-progress tasks: {$backlog['in_progress_count']}");
        if ($backlog['active_epic'] !== null) {
            $ae = $backlog['active_epic'];
            $io->writeln("  active epic: {$ae['id']}  [{$ae['status']}]  — {$ae['title']}");
        }
        foreach ($backlog['in_progress_tasks'] as $t) {
            $next = $t['next_step'] !== null && $t['next_step'] !== '' ? "  — next: {$t['next_step']}" : '';
            $io->writeln("    • [{$t['status']}] {$t['id']}  ({$t['recipe']}/{$t['risk']})  {$t['title']}{$next}");
        }

        if ($envelope['recent_traces'] !== []) {
            $io->section('Recent traces');
            foreach ($envelope['recent_traces'] as $tr) {
                $tail = $tr['last_event'];
                $line = "  • {$tr['id']}  ({$tr['created_at']})";
                if ($tail !== null) {
                    $line .= " — last: [{$tail['kind']}] {$tail['summary']}";
                }
                $io->writeln($line);
            }
        }

        if ($envelope['last_verify'] !== null) {
            $lv = $envelope['last_verify'];
            $io->section('Last verify');
            $io->writeln("  trace: {$lv['trace_id']}  at: {$lv['at']}");
            $io->writeln("  verdict: " . ($lv['verdict'] ?? 'unknown') . "  — {$lv['summary']}");
        }

        $io->section('Next');
        $io->writeln('  ' . $envelope['suggest_next']);
        foreach ($envelope['next_command'] as $nc) {
            $io->writeln("  → bin/semitexa {$nc['cmd']} " . implode(' ', $nc['args']) . "   # {$nc['why']}");
        }
    }
}
