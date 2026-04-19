<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Ai\Work\BacklogScope;
use Semitexa\Dev\Ai\Work\Epic;
use Semitexa\Dev\Ai\Work\EpicStatus;
use Semitexa\Dev\Ai\Work\EpicStore;
use Semitexa\Dev\Ai\Work\WorkId;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *   bin/semitexa ai:epic start  --id=ep-fix-auth --title="..." [--goal="..."]
 *   bin/semitexa ai:epic list   [--json]
 *   bin/semitexa ai:epic show   --id=ep-fix-auth [--json]
 *   bin/semitexa ai:epic update --id=ep-fix-auth [--title=...] [--goal=...] [--status=...]
 *
 * NDJSON is the default output (one object per line); `--json` flips to a
 * single envelope. Matches `ai:trace` conventions so a shared formatter
 * could eventually merge outputs across ai:* commands.
 */
#[AsCommand(name: 'ai:epic', description: 'Manage epics for agent-driven work decomposition (start/list/show/update)')]
final class AiEpicCommand extends BaseCommand
{
    private const string ACTION_START  = 'start';
    private const string ACTION_LIST   = 'list';
    private const string ACTION_SHOW   = 'show';
    private const string ACTION_UPDATE = 'update';

    #[InjectAsReadonly]
    protected EpicStore $epicStore;

    #[InjectAsReadonly]
    protected TraceAutoAppender $traceAppender;

    #[InjectAsReadonly]
    protected BacklogHygiene $hygiene;

    public function __construct()
    {
        parent::__construct('ai:epic');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'start | list | show | update')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Epic id (a-z, 0-9, -, _)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Epic title (required on start)')
            ->addOption('goal', null, InputOption::VALUE_REQUIRED, 'One-line goal (recommended on start)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Epic status: ' . implode('|', EpicStatus::all()))
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'List filter: ' . implode('|', BacklogScope::all()) . ' (default: active)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON envelope instead of NDJSON')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Append a note event to this ai:trace id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $json = (bool) $input->getOption('json');

        return match ($action) {
            self::ACTION_START  => $this->start($input, $output, $json),
            self::ACTION_LIST   => $this->list($input, $output, $json),
            self::ACTION_SHOW   => $this->show($input, $output, $json),
            self::ACTION_UPDATE => $this->update($input, $output, $json),
            default             => $this->error($output, "unknown action: '{$action}' (expected start | list | show | update)", $json),
        };
    }

    private function start(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        $title = (string) ($input->getOption('title') ?? '');
        if ($title === '') {
            return $this->error($output, '--title is required for start', $jsonMode);
        }
        if ($this->epicStore->exists($id)) {
            return $this->error($output, "epic '{$id}' already exists", $jsonMode);
        }

        $now = date('c');
        $epic = new Epic(
            id:        $id,
            title:     $title,
            goal:      (string) ($input->getOption('goal') ?? ''),
            status:    EpicStatus::NEW,
            createdAt: $now,
            updatedAt: $now,
            taskIds:   [],
        );
        $this->epicStore->save($epic);

        $this->appendToTrace($input, $output, TraceEventKind::NOTE, "epic '{$id}' started — {$title}", [
            'artifact' => 'semitexa.ai-work.epic-start/v1',
            'epic_id'  => $id,
            'title'    => $title,
            'goal'     => $epic->goal,
        ]);

        return $this->emitEpic($output, $epic, 'epic_started', $jsonMode);
    }

    private function list(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $scopeRaw = (string) ($input->getOption('scope') ?? BacklogScope::ACTIVE->value);
        try {
            $scope = BacklogScope::parse($scopeRaw);
        } catch (\InvalidArgumentException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $all = $this->epicStore->list();
        $rows = [];
        $hiddenByScope = 0;
        foreach ($all as $epic) {
            $assessment = $this->hygiene->assessEpic($epic);
            if (!$this->hygiene->epicInScope($epic, $assessment, $scope)) {
                $hiddenByScope++;
                continue;
            }
            $rows[] = $epic->toArray() + [
                'quality'          => $assessment->quality->value,
                'issues'           => $assessment->issues,
                'suggested_status' => $assessment->suggestedStatus,
                'suggested_action' => $assessment->suggestedAction,
            ];
        }

        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact'         => 'semitexa.ai-work.epic-list/v1',
                'scope'            => $scope->value,
                'epics'            => $rows,
                'epic_count'       => count($rows),
                'hidden_by_scope'  => $hiddenByScope,
                'total_in_store'   => count($all),
            ], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $output->writeln(json_encode([
            'kind'            => 'summary',
            'scope'           => $scope->value,
            'epic_count'      => count($rows),
            'hidden_by_scope' => $hiddenByScope,
            'total_in_store'  => count($all),
        ], JSON_UNESCAPED_SLASHES));
        foreach ($rows as $row) {
            $output->writeln(json_encode(['kind' => 'epic'] + $row, JSON_UNESCAPED_SLASHES));
        }
        return self::SUCCESS;
    }

    private function show(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        try {
            $epic = $this->epicStore->get($id);
        } catch (\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }
        return $this->emitEpic($output, $epic, 'epic', $jsonMode);
    }

    private function update(InputInterface $input, OutputInterface $output, bool $jsonMode): int
    {
        $id = $this->requireId($input, $output, $jsonMode);
        if ($id === null) {
            return self::FAILURE;
        }
        try {
            $epic = $this->epicStore->get($id);
        } catch (\RuntimeException $e) {
            return $this->error($output, $e->getMessage(), $jsonMode);
        }

        $title = $input->getOption('title');
        $goal = $input->getOption('goal');
        $statusRaw = $input->getOption('status');
        $status = null;
        if ($statusRaw !== null && $statusRaw !== '') {
            try {
                $status = EpicStatus::parse((string) $statusRaw);
            } catch (\InvalidArgumentException $e) {
                return $this->error($output, $e->getMessage(), $jsonMode);
            }
        }

        if ($title === null && $goal === null && $status === null) {
            return $this->error($output, 'update requires at least one of --title, --goal, --status', $jsonMode);
        }

        $updated = $epic->with(
            title:     $title !== null ? (string) $title : null,
            goal:      $goal !== null ? (string) $goal : null,
            status:    $status,
            updatedAt: date('c'),
        );
        $this->epicStore->save($updated);
        $updated = $updated->withTaskIds($epic->taskIds);

        $this->appendToTrace($input, $output, TraceEventKind::NOTE, "epic '{$id}' updated", [
            'artifact' => 'semitexa.ai-work.epic-update/v1',
            'epic_id'  => $id,
            'changes'  => array_filter([
                'title'  => $title,
                'goal'   => $goal,
                'status' => $status?->value,
            ], static fn($v) => $v !== null),
        ]);

        return $this->emitEpic($output, $updated, 'epic_updated', $jsonMode);
    }

    private function emitEpic(OutputInterface $output, Epic $epic, string $kind, bool $jsonMode): int
    {
        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.ai-work.epic/v1',
                'epic'     => $epic->toArray(),
            ], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln(json_encode(['kind' => $kind] + $epic->toArray(), JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function requireId(InputInterface $input, OutputInterface $output, bool $jsonMode): ?string
    {
        $id = (string) ($input->getOption('id') ?? '');
        if ($id === '') {
            $this->error($output, '--id is required', $jsonMode);
            return null;
        }
        try {
            WorkId::assertValid($id, 'epic id');
        } catch (\InvalidArgumentException $e) {
            $this->error($output, $e->getMessage(), $jsonMode);
            return null;
        }
        return $id;
    }

    private function error(OutputInterface $output, string $message, bool $jsonMode): int
    {
        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa.ai-work.epic/v1',
                'status'   => 'error',
                'error'    => $message,
            ], JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(json_encode([
                'kind'  => 'error',
                'error' => $message,
            ], JSON_UNESCAPED_SLASHES));
        }
        return self::FAILURE;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendToTrace(InputInterface $input, OutputInterface $output, string $kind, string $summary, array $payload): void
    {
        $this->traceAppender->appendIfActive($input, $output, $kind, $summary, $payload);
    }
}
