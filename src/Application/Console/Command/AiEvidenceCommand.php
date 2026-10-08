<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceData;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceKind;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceRecord;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceStore;
use Semitexa\Dev\Application\Service\Ai\Evidence\PrivateEvidencePatterns;
use Semitexa\Dev\Application\Service\Ai\Evidence\PublicationGate;
use Semitexa\Dev\Application\Service\Ai\Evidence\ScratchRetention;
use Semitexa\Dev\Application\Service\Trace\TraceRetention;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Review evidence — screenshots, recordings, traces, logs, graph exports —
 * kept private, with a passport, until the operator lets one out.
 *
 *   ai:evidence add <file> --kind=screenshot --data=synthetic [--note=…] [--ttl-days=14] [--move]
 *   ai:evidence list                    what is kept, what expired, what lies outside the store
 *   ai:evidence show <id>
 *   ai:evidence prune [--dry-run] [--tmp]  remove expired evidence and request traces
 *                                       older than 7 days; --tmp also var/tmp entries
 *                                       untouched for 14 days
 *   ai:evidence publish <id> --to="PR semitexa/dev#120" [--allow-real-data]
 *
 * publish checks the evidence, then asks the person at the terminal to type
 * its id. Without a terminal — an agent's shell, a pipe, --no-interaction —
 * it refuses (Glow Labs PixelLeak, 2026-09). This stops the ACCIDENT: an agent
 * attaching proof on its own initiative. It is not a wall against an agent
 * set on getting through — a pseudo-terminal (`script`) passes the check;
 * enforcing that outside the model is ep-agent-policy-boundary's job.
 */
#[AsCommand(name: 'ai:evidence', description: 'Keep review evidence (screenshots, traces, exports) private with a passport and an expiry; publishing one needs the operator at a terminal')]
final class AiEvidenceCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('ai:evidence');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'add | list | show | prune | publish', 'list')
            ->addArgument('target', InputArgument::OPTIONAL, 'add: the file; show/publish: the evidence id')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'add: ' . implode(' | ', EvidenceKind::values()))
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'add: what it was made from — ' . implode(' | ', EvidenceData::values()) . ' (required, no default)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'add: what it proves, in one line', '')
            ->addOption('ttl-days', null, InputOption::VALUE_REQUIRED, 'add: keep it this many days (1..' . EvidenceStore::MAX_TTL_DAYS . ')', (string) EvidenceStore::DEFAULT_TTL_DAYS)
            ->addOption('move', null, InputOption::VALUE_NONE, 'add: move the file into the store instead of copying it')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'publish: where it goes, e.g. "PR semitexa/dev#120"')
            ->addOption('allow-real-data', null, InputOption::VALUE_NONE, 'publish: a trace, log or export of real data may go (never a picture of real data)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'prune: say what would go')
            ->addOption('tmp', null, InputOption::VALUE_NONE, 'prune: also var/tmp entries nothing touched for ' . ScratchRetention::DAYS . ' days (see them first with --dry-run)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = new EvidenceStore($this->getProjectRoot());
        $action = (string) $input->getArgument('action');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            // Whatever a tool dropped in the inbox is recorded before anything
            // is listed, pruned or published.
            $inbox = $store->adopt($now);
            $envelope = match ($action) {
                'add' => $this->add($store, $input, $now),
                'list' => $this->listing($store, $now),
                'show' => ['action' => 'show', 'evidence' => $this->recordOf($store, $input, $now)],
                'prune' => $this->prune($store, $input, $now),
                'publish' => $this->publish($store, $input, $output, $now),
                default => throw new \InvalidArgumentException("unknown action '{$action}' (expected add | list | show | prune | publish)"),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->emit($output, $input, ['action' => $action, 'error' => $e->getMessage()], self::FAILURE);
        }

        $envelope['inbox'] = [
            'adopted' => array_map(static fn (EvidenceRecord $r): string => $r->id, $inbox['adopted']),
            'rejected' => $inbox['rejected'],
        ];

        return $this->emit($output, $input, $envelope, isset($envelope['refused']) ? self::FAILURE : self::SUCCESS);
    }

    /** @return array<string, mixed> */
    private function add(EvidenceStore $store, InputInterface $input, \DateTimeImmutable $now): array
    {
        $file = $input->getArgument('target');
        if (!is_string($file) || $file === '') {
            throw new \InvalidArgumentException('add needs the file: ai:evidence add var/tmp/shot.png --kind=screenshot --data=synthetic');
        }
        $kind = EvidenceKind::tryFrom((string) $input->getOption('kind'))
            ?? throw new \InvalidArgumentException('--kind must be one of: ' . implode(', ', EvidenceKind::values()));
        $data = EvidenceData::tryFrom((string) $input->getOption('data'))
            ?? throw new \InvalidArgumentException('--data must be said: ' . implode(' or ', EvidenceData::values()) . '. Not knowing is "real".');
        $ttl = (string) $input->getOption('ttl-days');
        if (preg_match('/^\d{1,3}$/', $ttl) !== 1) {
            throw new \InvalidArgumentException('--ttl-days must be a whole number of days');
        }
        $by = getenv(AgentRegistry::ENV);
        $record = $store->add($file, $kind, $data, (string) $input->getOption('note'), is_string($by) && $by !== '' ? $by : 'cli', (int) $ttl, (bool) $input->getOption('move'), $now);
        // Recording clears what expired. Here, not in the store's add(): the
        // inbox adoption every command runs first goes through add() too, and
        // `prune --dry-run` must not delete anything on the way.
        $store->prune($now, false);

        return ['action' => 'add', 'evidence' => $this->describe($store, $record, $now)];
    }

    /** @return array<string, mixed> */
    private function listing(EvidenceStore $store, \DateTimeImmutable $now): array
    {
        $all = $store->all();

        return [
            'action' => 'list',
            'evidence' => array_map(fn (EvidenceRecord $r): array => $this->describe($store, $r, $now), $all['records']),
            'unreadable' => $all['unreadable'],
            'unregistered' => $store->unregistered(),
        ];
    }

    /** @return array<string, mixed> */
    private function prune(EvidenceStore $store, InputInterface $input, \DateTimeImmutable $now): array
    {
        $dry = (bool) $input->getOption('dry-run');
        $removed = $store->prune($now, $dry);
        $root = rtrim($this->getProjectRoot(), '/');
        $traces = TraceRetention::sweep(TraceRetention::directory(), $now->getTimestamp(), $dry, PHP_INT_MAX);

        $scratch = null;
        if ((bool) $input->getOption('tmp')) {
            $retention = new ScratchRetention($root);
            $scratch = $retention->stale($now);
            if (!$dry) {
                $scratch = $retention->remove($scratch);
            }
        }

        return [
            'action' => 'prune',
            'dry_run' => $dry,
            'removed' => array_map(static fn (EvidenceRecord $r): string => $r->id, $removed),
            'bytes' => array_sum(array_map(static fn (EvidenceRecord $r): int => $r->bytes, $removed)),
            'traces' => count($traces),
            'tmp' => $scratch,
        ];
    }

    /** @return array<string, mixed> */
    private function publish(EvidenceStore $store, InputInterface $input, OutputInterface $output, \DateTimeImmutable $now): array
    {
        $record = $store->find((string) $input->getArgument('target'))
            ?? throw new \InvalidArgumentException('No evidence with that id; ai:evidence list shows them.');
        $to = trim((string) $input->getOption('to'));
        if ($to === '') {
            throw new \InvalidArgumentException('--to must say where it goes, e.g. --to="PR semitexa/dev#120": the approval is for one place.');
        }

        $refusals = (new PublicationGate(PrivateEvidencePatterns::load()))
            ->refusals($record, $store->fileOf($record), (bool) $input->getOption('allow-real-data'), $now);
        if ($refusals !== []) {
            return ['action' => 'publish', 'id' => $record->id, 'refused' => $refusals];
        }

        if (!$this->operatorAtTerminal($input)) {
            return ['action' => 'publish', 'id' => $record->id, 'refused' => [
                'publishing needs the operator at a terminal, and this is not one (an agent shell, a pipe or --no-interaction). '
                . 'Ask the operator to run, in their own terminal: bin/semitexa ai:evidence publish ' . $record->id . ' --to=' . escapeshellarg($to),
            ]];
        }

        $question = new Question(sprintf("Publish %s (%s, %s data) to %s?\nType its id to approve: ", $record->file, $record->kind->value, $record->data->value, $to));
        $answer = $this->getHelper('question')->ask($input, $output, $question);
        if (!is_string($answer) || trim($answer) !== $record->id) {
            return ['action' => 'publish', 'id' => $record->id, 'refused' => ['not approved: the id typed did not match']];
        }

        $record = $record->withPublication($to, self::operator(), $now);
        $store->save($record);

        return ['action' => 'publish', 'evidence' => $this->describe($store, $record, $now), 'approved_file' => $this->relative($store->fileOf($record))];
    }

    /** A person, not a process: interactive input on a real terminal. */
    private function operatorAtTerminal(InputInterface $input): bool
    {
        return $input->isInteractive() && function_exists('posix_isatty') && posix_isatty(STDIN);
    }

    private static function operator(): string
    {
        $user = getenv('USER');
        if (is_string($user) && $user !== '') {
            return $user;
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $info = function_exists('posix_getpwuid') ? posix_getpwuid((int) $uid) : false;

        return is_array($info) && is_string($info['name'] ?? null) ? $info['name'] : 'uid ' . $uid;
    }

    /** @return array<string, mixed> */
    private function recordOf(EvidenceStore $store, InputInterface $input, \DateTimeImmutable $now): array
    {
        $record = $store->find((string) $input->getArgument('target'))
            ?? throw new \InvalidArgumentException('No evidence with that id; ai:evidence list shows them.');

        return $this->describe($store, $record, $now);
    }

    /** @return array<string, mixed> */
    private function describe(EvidenceStore $store, EvidenceRecord $record, \DateTimeImmutable $now): array
    {
        return $record->toArray() + ['path' => $this->relative($store->fileOf($record)), 'expired' => $record->isExpired($now)];
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->getProjectRoot(), '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /** @param array<string, mixed> $envelope */
    private function emit(OutputInterface $output, InputInterface $input, array $envelope, int $code): int
    {
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($envelope + ['ok' => $code === self::SUCCESS], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return $code;
        }

        if (isset($envelope['error'])) {
            $output->writeln('<error>' . $envelope['error'] . '</error>');

            return $code;
        }
        foreach ($envelope['inbox']['rejected'] ?? [] as $name => $why) {
            $output->writeln(sprintf('<comment>%s/%s stays in the inbox:</comment> %s', EvidenceStore::INBOX, $name, $why));
        }
        foreach ($envelope['refused'] ?? [] as $reason) {
            $output->writeln('<error>Refused:</error> ' . $reason);
        }
        if (isset($envelope['evidence']) && is_array($envelope['evidence']) && isset($envelope['evidence']['id'])) {
            $this->line($output, $envelope['evidence']);
        }
        foreach (($envelope['action'] ?? '') === 'list' ? $envelope['evidence'] : [] as $row) {
            $this->line($output, $row);
        }
        if (($envelope['action'] ?? '') === 'list') {
            if ($envelope['evidence'] === []) {
                $output->writeln('No evidence in ' . EvidenceStore::DIR . '/.');
            }
            foreach ($envelope['unreadable'] as $name) {
                $output->writeln(sprintf('<comment>%s/%s has no readable passport: nobody can say what it holds.</comment>', EvidenceStore::DIR, $name));
            }
            foreach ($envelope['unregistered'] as $source) {
                $output->writeln(sprintf(
                    '<comment>Outside the store:</comment> %s — %d file(s), %s, oldest %s (%s). Not private by any rule and never expires; record what you need with ai:evidence add.',
                    $source['dir'], $source['files'], self::bytes($source['bytes']), $source['oldest'] ?? '?', $source['kind'],
                ));
            }
        }
        if (($envelope['action'] ?? '') === 'prune') {
            $verb = $envelope['dry_run'] ? 'Would remove' : 'Removed';
            $output->writeln(sprintf('%s %d expired evidence item(s), %s.', $verb, count($envelope['removed']), self::bytes((int) $envelope['bytes'])));
            $output->writeln(sprintf('%s %d request trace(s) older than %d days.', $verb, $envelope['traces'], TraceRetention::DAYS));
            if (is_array($envelope['tmp'])) {
                foreach ($envelope['tmp'] as $entry) {
                    $output->writeln(sprintf('  %s  %8s  last touched %s', $entry['path'], self::bytes($entry['bytes']), $entry['newest']));
                }
                $output->writeln(sprintf('%s %d var/tmp entr%s untouched for %d days, %s.', $verb, count($envelope['tmp']), count($envelope['tmp']) === 1 ? 'y' : 'ies', ScratchRetention::DAYS, self::bytes(array_sum(array_column($envelope['tmp'], 'bytes')))));
            }
        }
        if (isset($envelope['approved_file'])) {
            $output->writeln(sprintf('<info>Approved.</info> Upload %s to %s — that one place.', $envelope['approved_file'], $envelope['evidence']['publications'][array_key_last($envelope['evidence']['publications'])]['to']));
        }

        return $code;
    }

    /** @param array<string, mixed> $row */
    private function line(OutputInterface $output, array $row): void
    {
        $output->writeln(sprintf(
            '%s  %-12s %-9s %-9s %8s  %s%s  %s',
            $row['id'],
            $row['kind'],
            $row['data'],
            $row['visibility'],
            self::bytes((int) $row['bytes']),
            $row['expired'] ? 'EXPIRED ' : 'until ',
            substr((string) $row['expires_at'], 0, 10),
            $row['path'] . ($row['note'] !== '' ? ' — ' . $row['note'] : ''),
        ));
    }

    private static function bytes(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : ($n >= 1024 ? round($n / 1024) . ' KB' : $n . ' B');
    }
}
