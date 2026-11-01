<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Decision\Decision;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionScope;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionStatus;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionStore;
use Semitexa\Dev\Application\Service\Ai\Work\WorkId;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The owner's decisions (ep-project-charter), kept as project data instead of
 * in one agent's memory: any agent reads them before asking the owner, and
 * records the answer after.
 *
 *   bin/semitexa ai:decision record --words="Так, випускай" --means="one stable release" --applies-to=release --scope=once
 *   bin/semitexa ai:decision list [--applies-to=release] [--all]
 *   bin/semitexa ai:decision consume --id=<id> --act="released 2026.11.01.0448"
 *   bin/semitexa ai:decision revoke --id=<id>
 */
#[AsCommand(name: 'ai:decision', description: 'The project owner\'s decisions, with scope (standing | once), expiry and consumption: record | list | show | consume | revoke')]
final class AiDecisionCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-decision/v1';

    #[InjectAsReadonly]
    protected DecisionStore $decisionStore;

    public function __construct()
    {
        parent::__construct('ai:decision');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'record | list | show | consume | revoke')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Decision id (record: optional, one is made from the date and --applies-to)')
            ->addOption('words', null, InputOption::VALUE_REQUIRED, 'record: the owner\'s own words')
            ->addOption('means', null, InputOption::VALUE_REQUIRED, 'record: what it means for the work, in one line')
            ->addOption('applies-to', null, InputOption::VALUE_REQUIRED, 'record: what it is about, a short tag (release, dependency, data, docs, ...); list: filter')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'record: standing (holds until revoked) | once (spent by the act it allows)')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'record: when it stops holding: a date (it holds through that day, UTC) or an ISO-8601 time')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'record: who decided (default: owner)')
            ->addOption('act', null, InputOption::VALUE_REQUIRED, 'consume: the act that spent it')
            ->addOption('all', null, InputOption::VALUE_NONE, 'list: consumed, revoked and expired ones too')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON envelope (always; kept for the ai:* convention)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            return match ($action) {
                'record' => $this->record($input, $output, $now),
                'list' => $this->list($input, $output, $now),
                'show' => $this->emit($output, ['decision' => $this->decisionStore->get($this->id($input))->toArray($now)]),
                'consume' => $this->consume($input, $output, $now),
                'revoke' => $this->revoke($input, $output, $now),
                default => $this->fail($output, 'unknown action "' . (is_string($action) ? $action : '') . '" (expected record | list | show | consume | revoke)'),
            };
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }
    }

    private function record(InputInterface $input, OutputInterface $output, \DateTimeImmutable $now): int
    {
        $words = self::text($input, 'words');
        $means = self::text($input, 'means');
        $appliesTo = strtolower(self::text($input, 'applies-to'));
        $scope = DecisionScope::tryFrom(self::text($input, 'scope'));
        if ($words === '' || $means === '' || $appliesTo === '' || $scope === null) {
            return $this->fail($output, 'record needs --words (the owner\'s), --means, --applies-to and --scope=standing|once');
        }
        $expires = self::text($input, 'expires');
        $expiresAt = null;
        if ($expires !== '') {
            $at = self::expiry($expires);
            if ($at === null) {
                return $this->fail($output, "--expires \"{$expires}\" is not a date");
            }
            if ($at <= $now) {
                return $this->fail($output, "--expires {$expires} is already past");
            }
            $expiresAt = $at->format('c');
        }
        $wanted = self::text($input, 'id');
        $by = self::text($input, 'by');

        return $this->decisionStore->locked(function () use ($output, $now, $wanted, $words, $means, $appliesTo, $scope, $by, $expiresAt): int {
            if ($wanted !== '' && $this->decisionStore->exists($wanted)) {
                return $this->fail($output, "decision '{$wanted}' already exists");
            }
            $id = $wanted !== '' ? $wanted : $this->freshId($now, $appliesTo);
            $decision = new Decision($id, $words, $means, $appliesTo, $scope, $now->format('c'), $by !== '' ? $by : 'owner', $expiresAt);
            $this->decisionStore->save($decision);

            return $this->emit($output, ['status' => 'ok', 'decision' => $decision->toArray($now)]);
        });
    }

    private function list(InputInterface $input, OutputInterface $output, \DateTimeImmutable $now): int
    {
        $appliesTo = strtolower(self::text($input, 'applies-to'));
        $all = (bool) $input->getOption('all');
        $decisions = array_values(array_filter(
            $this->decisionStore->list(),
            static fn (Decision $d): bool => ($appliesTo === '' || $d->appliesTo === $appliesTo)
                && ($all || $d->status($now) === DecisionStatus::ACTIVE),
        ));

        return $this->emit($output, [
            'filter' => ['applies_to' => $appliesTo !== '' ? $appliesTo : null, 'status' => $all ? 'all' : 'active'],
            'count' => count($decisions),
            'decisions' => array_map(static fn (Decision $d): array => $d->toArray($now), $decisions),
        ]);
    }

    private function consume(InputInterface $input, OutputInterface $output, \DateTimeImmutable $now): int
    {
        return $this->decisionStore->locked(fn (): int => $this->consumeLocked($input, $output, $now));
    }

    private function consumeLocked(InputInterface $input, OutputInterface $output, \DateTimeImmutable $now): int
    {
        $decision = $this->decisionStore->get($this->id($input));
        $act = self::text($input, 'act');
        if ($act === '') {
            return $this->fail($output, 'consume needs --act: what spent it');
        }
        if ($decision->scope !== DecisionScope::ONCE) {
            return $this->fail($output, "decision '{$decision->id}' is standing: it is revoked, not consumed");
        }
        $status = $decision->status($now);
        if ($status !== DecisionStatus::ACTIVE) {
            return $this->fail($output, "decision '{$decision->id}' is {$status->value}: it allows nothing more");
        }
        $spent = $decision->consumed($now->format('c'), $act);
        $this->decisionStore->save($spent);

        return $this->emit($output, ['status' => 'ok', 'decision' => $spent->toArray($now)]);
    }

    private function revoke(InputInterface $input, OutputInterface $output, \DateTimeImmutable $now): int
    {
        return $this->decisionStore->locked(fn (): int => $this->revokeLocked($input, $output, $now));
    }

    private function revokeLocked(InputInterface $input, OutputInterface $output, \DateTimeImmutable $now): int
    {
        $decision = $this->decisionStore->get($this->id($input));
        $status = $decision->status($now);
        if ($status !== DecisionStatus::ACTIVE) {
            // A spent approval stays on record as spent: revoking it would
            // report "taken back" for an act that happened.
            return $this->fail($output, "decision '{$decision->id}' is {$status->value}: nothing left to revoke");
        }
        $revoked = $decision->revokedNow();
        $this->decisionStore->save($revoked);

        return $this->emit($output, ['status' => 'ok', 'decision' => $revoked->toArray($now)]);
    }

    private function id(InputInterface $input): string
    {
        $id = self::text($input, 'id');
        if ($id === '') {
            throw new \InvalidArgumentException('--id is required');
        }
        WorkId::assertValid($id, 'decision id');

        return $id;
    }

    /** A bare date holds through that day (UTC); a time is taken as given. Null when it is neither. */
    private static function expiry(string $text): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, $utc);
            // 2026-02-30 is not a day, whatever PHP rolls it into.
            return $day !== false && $day->format('Y-m-d') === $text ? $day->modify('+1 day') : null;
        }
        try {
            return new \DateTimeImmutable($text, $utc);
        } catch (\Exception) {
            return null;
        }
    }

    /** d-<date>-<applies-to>[-n]: readable in a listing, unique in the directory. */
    private function freshId(\DateTimeImmutable $now, string $appliesTo): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $appliesTo), '-');
        $base = substr('d-' . $now->format('Ymd') . '-' . ($slug !== '' ? $slug : 'decision'), 0, 60);
        $id = $base;
        for ($n = 2; $this->decisionStore->exists($id); $n++) {
            $id = $base . '-' . $n;
        }

        return $id;
    }

    private static function text(InputInterface $input, string $option): string
    {
        $value = $input->getOption($option);

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $payload */
    private function emit(OutputInterface $output, array $payload): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::FAILURE;
    }
}
