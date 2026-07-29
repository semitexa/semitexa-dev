<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Console\CommandDelegator;
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
        // Distinct from `capabilities` on purpose: that one lists the CLI
        // commands available to run, this one lists what the installed
        // FRAMEWORK can do (deferred regions, components, live transport).
        // Both answer "what can I do here"; merging them buries the handful of
        // mechanisms under a wall of command help.
        'mechanisms'   => 'dev:graph:mechanisms',
        'project'      => 'dev:graph:project',
        'module'       => 'dev:graph:module',
        'route'        => 'dev:graph:route',
        'event'        => 'dev:graph:event',
        'logs'         => 'logs:app',
        // `path` explains a file/directory path using global module-structure
        // rules + any package-local extension. Auto-selected when --path is
        // passed without an explicit subject (see execute()).
        'path'         => 'dev:graph:path',
    ];

    public function __construct()
    {
        parent::__construct('ai:ask');
    }

    protected function configure(): void
    {
        $this
            // Subject is now OPTIONAL: when omitted, the command auto-selects
            // `path` if --path is provided (lets `ai:ask --path=…` work
            // without naming a subject). Existing subjects keep their full
            // routing behavior.
            ->addArgument('subject', InputArgument::OPTIONAL, 'One of: ' . implode(', ', array_keys(self::SUBJECT_MAP)) . ' (omit if --path is provided to auto-select `path`)')
            // Union of options accepted by the delegated targets. Only the
            // ones the target actually declares are forwarded (see
            // CommandDelegator). Anything else is silently dropped.
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module/event name (for subject=module|event)')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path: route path (subject=route) | file/directory path (subject=path, default when --path used)')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method (for subject=route)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Log file alias (for subject=logs)')
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'Line count (for subject=logs)')
            ->addOption('grep', null, InputOption::VALUE_REQUIRED, 'Filter (for subject=logs)')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Log level filter (for subject=logs)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Time window (for subject=logs)')
            ->addOption('around', null, InputOption::VALUE_REQUIRED, 'Context timestamp (for subject=logs)')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context radius (for subject=logs)')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available logs (for subject=logs)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Capability id (for subject=mechanisms)')
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'Capability area prefix, e.g. ssr|ui (for subject=mechanisms)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope (target-dependent shape)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subject = (string) ($input->getArgument('subject') ?? '');
        // When subject is omitted but --path is provided, default to
        // `path`. Keeps `ai:ask --path=foo` ergonomic for AI agents while
        // preserving the explicit-subject flow for everyone else.
        if ($subject === '' && (string) ($input->getOption('path') ?? '') !== '') {
            $subject = 'path';
        }
        if ($subject === '') {
            $output->writeln(json_encode([
                'kind'     => 'error',
                'error'    => 'missing subject (and no --path provided to auto-select)',
                'subjects' => array_keys(self::SUBJECT_MAP),
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }
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
