<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Discovery\ClassDiscovery;

/**
 * Every #[AsScheduledJob] the project declares — key, cron expression, retry
 * policy — read from the attributes, not the scheduler's tables. The live
 * panel draws one cron row per schedule and ticks it when a run lands.
 *
 * Attributes rather than the registry on purpose: dev must not depend on the
 * scheduler package (the attribute is looked up by NAME and its arguments
 * read raw, so this works whether or not the class is loadable), and the
 * declaration is what a presenter wants to see — "this job says every
 * 15 seconds" — even on a stack where the scheduler container is down.
 */
#[AsService]
final class ScheduleCatalog
{
    private const ATTRIBUTE = 'Semitexa\\Scheduler\\Attribute\\AsScheduledJob';
    private const TTL_SECONDS = 60;

    /** @var array{at: int, rows: list<array<string, mixed>>}|null */
    private static ?array $memo = null;

    /** @return list<array{key: string, cron: string, job: string, jobClass: string, maxAttempts: int, backoffS: int, pool: string}> */
    public function all(): array
    {
        if (self::$memo !== null && time() - self::$memo['at'] < self::TTL_SECONDS) {
            return self::$memo['rows'];
        }

        $rows = [];
        try {
            $discovery = new ClassDiscovery();
            foreach ($discovery->findClassesWithAttribute(self::ATTRIBUTE) as $class) {
                if (!is_string($class) || !class_exists($class)) {
                    continue;
                }
                $attrs = (new \ReflectionClass($class))->getAttributes(self::ATTRIBUTE);
                foreach ($attrs as $attr) {
                    $args = $attr->getArguments();
                    $key = (string) ($args['key'] ?? $args[0] ?? '');
                    $cron = (string) ($args['cronExpression'] ?? $args[1] ?? '');
                    if ($key === '' || $cron === '') {
                        continue;
                    }
                    $rows[] = [
                        'key' => $key,
                        'cron' => self::resolveEnv($cron),
                        'job' => self::short($class),
                        'jobClass' => $class,
                        'maxAttempts' => max(1, (int) ($args['maxAttempts'] ?? 1)),
                        'backoffS' => max(0, (int) ($args['retryBackoffSeconds'] ?? 0)),
                        'pool' => (string) ($args['pool'] ?? 'default'),
                    ];
                }
            }
        } catch (\Throwable) {
            // No schedules is a valid answer; a broken discovery must not take the panel down.
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        self::$memo = ['at' => time(), 'rows' => $rows];

        return $rows;
    }

    /** `%env(SCHEDULE_X)%`-style expressions resolve to the value when the scheduler's resolver exists. */
    private static function resolveEnv(string $cron): string
    {
        // The declaration form `env::NAME::default`: the env value when set,
        // the default otherwise — what the scheduler itself would run.
        if (preg_match('/^env::([A-Z0-9_]+)::(.+)$/', $cron, $m) === 1) {
            $fromEnv = getenv($m[1]);

            return is_string($fromEnv) && trim($fromEnv) !== '' ? trim($fromEnv) : trim($m[2]);
        }

        $resolver = 'Semitexa\\Scheduler\\Support\\EnvValueResolver';
        if (class_exists($resolver) && method_exists($resolver, 'resolve')) {
            try {
                $resolved = $resolver::resolve($cron);
                if (is_string($resolved) && trim($resolved) !== '') {
                    return trim($resolved);
                }
            } catch (\Throwable) {
            }
        }

        return $cron;
    }

    private static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
