<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentRegistry;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentSession;
use Semitexa\Dev\Application\Service\Ai\Presence\WorkspaceActivity;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Who is working in this workspace, and on what.
 *
 *   ai:agent join --name=codex --intent="…" [--repo=packages/semitexa-demo]*
 *   ai:agent list [--all]            live agents + what is being edited
 *   ai:agent beat [--task=<id>]      I am still here (every ai:* command does this)
 *   ai:agent leave                   I am done
 *
 * join prints the session id to export as SEMITEXA_AGENT_SESSION; from then on
 * every ai:* command this agent runs keeps the session live, and ai:work
 * records the task it takes.
 */
#[AsCommand(name: 'ai:agent', description: 'Agent presence: join with a name and intent, see who else is working and what is being edited, leave when done')]
final class AiAgentCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'join | list | beat | leave', 'list')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Your agent name: claude, codex, copilot, … (join)')
            ->addOption('intent', null, InputOption::VALUE_REQUIRED, 'One sentence: what you are about to do (join)')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A repo you will edit, e.g. packages/semitexa-demo (join, repeatable)')
            ->addOption('task', null, InputOption::VALUE_REQUIRED, 'The ai:work task you hold (beat)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Session id (leave; defaults to $' . AgentRegistry::ENV . ')')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include agents that left or went quiet (list)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->getProjectRoot();
        $registry = new AgentRegistry($root);
        $action = (string) $input->getArgument('action');

        try {
            $envelope = match ($action) {
                'join' => $this->join($registry, $input),
                'list' => ['action' => 'list'],
                'beat' => $this->beat($registry, $input),
                'leave' => $this->leave($registry, $input),
                default => throw new \InvalidArgumentException("unknown action '{$action}' (expected join | list | beat | leave)"),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->emit($output, $input, ['action' => $action, 'error' => $e->getMessage()], self::FAILURE);
        }

        $now = time();
        $self = $envelope['session']['id'] ?? (getenv(AgentRegistry::ENV) ?: null);
        $live = $registry->all(false, $now);
        $envelope['agents'] = array_map(
            static fn (AgentSession $s): array => $s->toArray() + ['live' => $s->isLive($now), 'silent_s' => $s->secondsSilent($now), 'you' => $s->id === $self],
            (bool) $input->getOption('all') ? $registry->all(true, $now) : $live,
        );
        $envelope['activity'] = (new WorkspaceActivity($root))->dirtyRepos($live, $now);

        return $this->emit($output, $input, $envelope, self::SUCCESS);
    }

    /** @return array<string, mixed> */
    private function join(AgentRegistry $registry, InputInterface $input): array
    {
        $session = $registry->join(
            (string) $input->getOption('name'),
            (string) $input->getOption('intent'),
            array_values(array_map('strval', (array) $input->getOption('repo'))),
        );

        return [
            'action' => 'join',
            'session' => $session->toArray(),
            'export' => 'export ' . AgentRegistry::ENV . '=' . $session->id,
            'next_command' => [
                ['cmd' => 'export', 'args' => [AgentRegistry::ENV . '=' . $session->id], 'why' => 'every ai:* command then keeps you listed as live; without it you go quiet in 15 minutes'],
                ['cmd' => 'ai:agent', 'args' => ['leave', '--json'], 'why' => 'when you are done, so nobody waits on you'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function beat(AgentRegistry $registry, InputInterface $input): array
    {
        if ($registry->current() === null) {
            throw new \InvalidArgumentException('no session: run ai:agent join first and export ' . AgentRegistry::ENV);
        }
        $task = $input->getOption('task');
        $registry->beat(is_string($task) && $task !== '' ? $task : null);

        return ['action' => 'beat', 'session' => $registry->current()?->toArray()];
    }

    /** @return array<string, mixed> */
    private function leave(AgentRegistry $registry, InputInterface $input): array
    {
        $id = (string) ($input->getOption('id') ?? getenv(AgentRegistry::ENV) ?: '');
        $session = $registry->leave($id);
        if ($session === null) {
            throw new \InvalidArgumentException("no session '{$id}' to leave");
        }

        return ['action' => 'leave', 'session' => $session->toArray()];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function emit(OutputInterface $output, InputInterface $input, array $envelope, int $exit): int
    {
        $envelope = ['artifact' => 'semitexa-dev.ai-agent/v1'] + $envelope;
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        if (isset($envelope['error'])) {
            $output->writeln('ai:agent: ' . $envelope['error']);

            return $exit;
        }
        if (isset($envelope['export'])) {
            $output->writeln('Joined. Run:  ' . $envelope['export']);
            $output->writeln('');
        }
        $output->writeln('Agents working now:');
        $agents = $envelope['agents'] ?? [];
        if ($agents === []) {
            $output->writeln('  nobody has joined');
        }
        foreach ($agents as $a) {
            $output->writeln(sprintf(
                '  %s%-16s %s — %s%s',
                $a['you'] ? '* ' : '  ',
                $a['id'],
                $a['live'] ? ($a['silent_s'] < 60 ? 'now' : intdiv($a['silent_s'], 60) . ' min ago') : ($a['ended_at'] !== null ? 'left' : 'quiet'),
                $a['intent'],
                ($a['task'] !== null ? '  [task ' . $a['task'] . ']' : '') . ($a['repos'] !== [] ? '  (' . implode(', ', $a['repos']) . ')' : ''),
            ));
        }
        $output->writeln('');
        $output->writeln('Uncommitted edits in the workspace:');
        $activity = $envelope['activity'] ?? [];
        if ($activity === []) {
            $output->writeln('  none');
        }
        foreach ($activity as $r) {
            $output->writeln(sprintf(
                '  %s %-34s %3d file(s), last edit %s — %s',
                $r['fresh'] ? '●' : '○',
                $r['repo'],
                $r['dirty'],
                $r['last_edit'] === '' ? '?' : substr($r['last_edit'], 11, 5) . ' UTC',
                $r['claimed_by'] === [] ? 'claimed by nobody' : 'claimed by ' . implode(', ', $r['claimed_by']),
            ));
        }

        return $exit;
    }
}
