<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Console\Command\Support\CommandDelegator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Agent-facing aggregator for read-only introspection. One entry point, one
 * argument (the "subject"), forwarded to the underlying command that owns
 * the implementation.
 *
 *   ai:ask capabilities [--json]
 *   ai:ask project      [--json]
 *   ai:ask module       --name=Billing [--json]
 *   ai:ask route        --path=/billing/{id} [--method=GET] [--json]
 *   ai:ask event        [--name=InvoicePaid] [--json]
 *   ai:ask logs         [--file=app] [--lines=200] [--grep=…] [--json]
 *
 * Output contracts (envelope shapes, JSON keys, exit codes) belong to the
 * dispatched targets — `ai:ask` only routes; the dev:graph:* / logs:app
 * commands own behavior.
 */
#[AsCommand(name: 'ai:ask', description: 'Agent-facing introspection aggregator (capabilities, project, module, route, event, logs)')]
final class AiAskCommand extends BaseCommand
{
    /**
     * Subject → underlying command. Keeping the list short is the point: if
     * a new read-only surface is needed, add it here rather than spawning
     * another top-level command.
     *
     * @var array<string, string>
     */
    private const SUBJECT_MAP = [
        'capabilities' => 'dev:graph:capabilities',
        'project'      => 'dev:graph:project',
        'module'       => 'dev:graph:module',
        'route'        => 'dev:graph:route',
        'event'        => 'dev:graph:event',
        'logs'         => 'logs:app',
    ];

    public function __construct()
    {
        parent::__construct('ai:ask');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('subject', InputArgument::REQUIRED, 'One of: ' . implode(', ', array_keys(self::SUBJECT_MAP)))
            // Union of options accepted by the delegated targets. Only the
            // ones the target actually declares are forwarded (see
            // CommandDelegator). Anything else is silently dropped.
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module/event name (for subject=module|event)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Route path (for subject=route)')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method (for subject=route)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Log file alias (for subject=logs)')
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'Line count (for subject=logs)')
            ->addOption('grep', null, InputOption::VALUE_REQUIRED, 'Filter (for subject=logs)')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Log level filter (for subject=logs)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Time window (for subject=logs)')
            ->addOption('around', null, InputOption::VALUE_REQUIRED, 'Context timestamp (for subject=logs)')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context radius (for subject=logs)')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available logs (for subject=logs)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope (target-dependent shape)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subject = (string) $input->getArgument('subject');
        $target = self::SUBJECT_MAP[$subject] ?? null;
        if ($target === null) {
            $output->writeln(json_encode([
                'kind'     => 'error',
                'error'    => "unknown subject '{$subject}'",
                'subjects' => array_keys(self::SUBJECT_MAP),
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln(json_encode([
                'kind'  => 'error',
                'error' => 'Application not available — cannot dispatch subject',
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        return CommandDelegator::run($app, $target, $input, $output);
    }
}
