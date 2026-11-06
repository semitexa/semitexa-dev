<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\State\AuditRecord;
use Semitexa\Dev\Application\Service\Ai\State\AuditStore;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Says that someone looked at a phase, so ai:state can tell clean from unread.
 *
 *   bin/semitexa ai:audit record --phase=right --covered="the Shop and Billing modules" [--by=...] [--with-framework]
 *   bin/semitexa ai:audit list
 *
 * What the look found is filed as tickets. This only says it happened.
 */
#[AsCommand(name: 'ai:audit', description: 'Record that right or fast was audited, and what it covered, so an empty phase reads as clean and not as unread: record | list')]
final class AiAuditCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-audit/v1';
    private const PHASES = [Phase::RIGHT, Phase::FAST];

    #[InjectAsReadonly]
    protected AuditStore $auditStore;

    public function __construct()
    {
        parent::__construct('ai:audit');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'record | list')
            ->addOption('phase', null, InputOption::VALUE_REQUIRED, 'record: right | fast')
            ->addOption('covered', null, InputOption::VALUE_REQUIRED, 'record: what was looked at, in a line')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'record: who looked (default: agent)')
            ->addOption('with-framework', null, InputOption::VALUE_NONE, 'record: the framework\'s code was read as well as ./src')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON envelope (always; kept for the ai:* convention)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        try {
            return match ($action) {
                'record' => $this->record($input, $output),
                'list' => $this->emit($output, ['audits' => array_map(static fn (AuditRecord $a): array => $a->toArray(), $this->auditStore->all())]),
                default => $this->fail($output, 'unknown action "' . (is_string($action) ? $action : '') . '" (expected record | list)'),
            };
        } catch (\RuntimeException | \JsonException $e) {
            return $this->fail($output, $e->getMessage());
        }
    }

    private function record(InputInterface $input, OutputInterface $output): int
    {
        $phase = Phase::tryFrom(self::text($input, 'phase'));
        if (!in_array($phase, self::PHASES, true)) {
            return $this->fail($output, '--phase must be right or fast: work is what was asked for, and its tickets say how far it is');
        }
        $covered = self::text($input, 'covered');
        if ($covered === '') {
            return $this->fail($output, '--covered is required: what was looked at, in a line');
        }
        $by = self::text($input, 'by');
        $audit = new AuditRecord($phase, gmdate(DATE_ATOM), $by !== '' ? $by : 'agent', $covered, (bool) $input->getOption('with-framework'));
        $this->auditStore->record($audit);

        return $this->emit($output, ['status' => 'ok', 'audit' => $audit->toArray()]);
    }

    private static function text(InputInterface $input, string $option): string
    {
        $value = $input->getOption($option);

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $payload */
    private function emit(OutputInterface $output, array $payload): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::FAILURE;
    }
}
