<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'describe:event', description: 'Show all listeners for a given event, or list all events with their listener count')]
final class DescribeEventCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Event class name (short or FQCN). Omit to list all events.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        AttributeDiscovery::initialize();
        EventListenerRegistry::ensureBuilt();

        $eventName = $input->getOption('name');

        if ($eventName) {
            return $this->describeOne($eventName, $input, $output);
        }

        return $this->listAll($input, $output);
    }

    private function describeOne(string $eventName, InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $eventClasses = $this->resolveEventClasses($eventName);
        if ($eventClasses === []) {
            $io->error("Event not found: {$eventName}");
            $io->note('Use describe:event (without --name) to see all registered events.');
            return Command::FAILURE;
        }
        if (count($eventClasses) > 1) {
            $io->error("Ambiguous event name: {$eventName}");
            $io->listing(array_values($eventClasses));
            $io->note('Use the full event class name with --name.');
            return Command::FAILURE;
        }

        $eventClass = $eventClasses[0];

        $listeners = EventListenerRegistry::getListeners($eventClass);

        $description = [
            'event' => $eventClass,
            'module' => ModuleRegistry::getModuleNameForClass($eventClass) ?? 'project',
            'file' => $this->resolveRelativeFile($eventClass),
            'listener_count' => count($listeners),
            'listeners' => [],
        ];

        foreach ($listeners as $l) {
            $description['listeners'][] = [
                'class' => $l['class'],
                'module' => ModuleRegistry::getModuleNameForClass($l['class']) ?? 'project',
                'file' => $this->resolveRelativeFile($l['class']),
                'execution' => $l['execution'],
                'priority' => $l['priority'] ?? 0,
                'transport' => $l['transport'] ?? null,
                'queue' => $l['queue'] ?? null,
            ];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.event-description/v1',
                'generated_at' => date('c'),
                'event' => $description,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->title("Event: " . (new \ReflectionClass($eventClass))->getShortName());
        $io->text([
            "Class:  {$eventClass}",
            "Module: {$description['module']}",
            "File:   {$description['file']}",
        ]);

        if ($listeners === []) {
            $io->newLine();
            $io->warning('No listeners registered for this event.');
            return Command::SUCCESS;
        }

        $io->section('Listeners (' . count($listeners) . ')');

        $tableRows = [];
        foreach ($description['listeners'] as $l) {
            $extra = [];
            if ($l['transport']) {
                $extra[] = "transport: {$l['transport']}";
            }
            if ($l['queue']) {
                $extra[] = "queue: {$l['queue']}";
            }
            $tableRows[] = [
                $l['class'],
                $l['module'],
                $l['execution'],
                (string) $l['priority'],
                $extra ? implode(', ', $extra) : '-',
            ];
        }

        $io->table(['Listener', 'Module', 'Execution', 'Priority', 'Extra'], $tableRows);
        return Command::SUCCESS;
    }

    private function listAll(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Collect all events with their listener counts
        $allListenerClasses = EventListenerRegistry::getAllListenerClasses();
        $eventMap = [];

        foreach ($allListenerClasses as $listenerClass) {
            try {
                $ref = new \ReflectionClass($listenerClass);
                $attrs = $ref->getAttributes(\Semitexa\Core\Attributes\AsEventListener::class);
                foreach ($attrs as $attr) {
                    $instance = $attr->newInstance();
                    $eventClass = $instance->event;
                    if (!isset($eventMap[$eventClass])) {
                        $eventMap[$eventClass] = [
                            'event' => $eventClass,
                            'module' => ModuleRegistry::getModuleNameForClass($eventClass) ?? 'project',
                            'listeners' => 0,
                            'sync' => 0,
                            'async' => 0,
                            'queued' => 0,
                        ];
                    }
                    $eventMap[$eventClass]['listeners']++;
                    $exec = is_string($instance->execution) ? $instance->execution : $instance->execution->value;
                    if (isset($eventMap[$eventClass][$exec])) {
                        $eventMap[$eventClass][$exec]++;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        ksort($eventMap);
        $events = array_values($eventMap);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.events-list/v1',
                'generated_at' => date('c'),
                'total_events' => count($events),
                'events' => $events,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->title('Registered Events');

        if ($events === []) {
            $io->warning('No events with listeners found.');
            return Command::SUCCESS;
        }

        $tableRows = [];
        foreach ($events as $e) {
            $shortName = $this->shortClassName($e['event']);
            $modes = [];
            if ($e['sync'] > 0) {
                $modes[] = "{$e['sync']} sync";
            }
            if ($e['async'] > 0) {
                $modes[] = "{$e['async']} async";
            }
            if ($e['queued'] > 0) {
                $modes[] = "{$e['queued']} queued";
            }
            $tableRows[] = [
                $shortName,
                $e['module'],
                (string) $e['listeners'],
                implode(', ', $modes),
            ];
        }

        $io->table(['Event', 'Module', 'Listeners', 'Execution Modes'], $tableRows);
        $io->text(count($events) . ' event(s) with listeners.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveEventClasses(string $name): array
    {
        if (class_exists($name)) {
            return [$name];
        }

        $allListenerClasses = EventListenerRegistry::getAllListenerClasses();
        $matches = [];

        foreach ($allListenerClasses as $listenerClass) {
            try {
                $ref = new \ReflectionClass($listenerClass);
                $attrs = $ref->getAttributes(\Semitexa\Core\Attributes\AsEventListener::class);
                foreach ($attrs as $attr) {
                    $instance = $attr->newInstance();
                    $eventClass = $instance->event;
                    if (class_exists($eventClass)) {
                        $shortName = (new \ReflectionClass($eventClass))->getShortName();
                        if ($eventClass === $name) {
                            return [$eventClass];
                        }
                        if ($shortName === $name) {
                            $matches[$eventClass] = $eventClass;
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values($matches);
    }

    private function resolveRelativeFile(string $className): ?string
    {
        try {
            $file = (new \ReflectionClass($className))->getFileName();
            if ($file === false) {
                return null;
            }
            $root = $this->getProjectRoot();
            if (str_starts_with($file, $root)) {
                return ltrim(substr($file, strlen($root)), '/');
            }
            return $file;
        } catch (\Throwable) {
            return null;
        }
    }

    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
