<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Dev\Application\Service\Ai\Verify\Transport\TransportDoorPolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fails when a framework-internal `/__` route is anything but KISS, HUG, a
 * system page or a development tool. See {@see TransportDoorPolicy}.
 */
#[AsCommand(
    name: 'lint:transport-doors',
    description: 'Fail on a framework-internal /__ route that is not KISS, HUG, a system page or a dev tool.',
)]
final class LintTransportDoorsCommand extends BaseCommand
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: KISS (/__semitexa_kiss) and HUG (/__semitexa_hug) are the whole browser-server transport by design, and a door added for one feature is never removed by the next. Learned 2026-10-04: git history showed four extra doors (component_event 2026-03, /__ui/event and /__ui/dispatch 2026-05, /__ui/form-doc 2026-06) although the rule was repeated to every agent.';

    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    public function __construct()
    {
        parent::__construct('lint:transport-doors');
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = [];
        foreach ($this->attributeDiscovery->getRoutes() as $route) {
            $routes[] = [
                'path' => (string) ($route['path'] ?? ''),
                'methods' => array_values((array) ($route['methods'] ?? [$route['method'] ?? 'GET'])),
                'class' => (string) ($route['class'] ?? ''),
            ];
        }
        $violations = TransportDoorPolicy::violations($routes);
        $pending = TransportDoorPolicy::PENDING;

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.lint-transport-doors/v1',
                'ok' => $violations === [],
                'violations' => $violations,
                'pending' => $pending,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return $violations === [] ? Command::SUCCESS : Command::FAILURE;
        }

        foreach ($violations as $v) {
            $output->writeln(sprintf('<error>%s [%s]</error> %s', $v['path'], implode(',', $v['methods']), $v['class']));
            $output->writeln('  ' . $v['reason']);
        }
        foreach ($pending as $path => $task) {
            $output->writeln(sprintf('<comment>pending</comment> %s — removed by %s', $path, $task));
        }
        if ($violations === []) {
            $output->writeln('lint:transport-doors → every /__ route is KISS, HUG, a system page or a dev tool.');
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('lint:transport-doors → %d extra door(s).', count($violations)));
        return Command::FAILURE;
    }
}
