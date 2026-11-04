<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Bench\LoadGenerator;
use Semitexa\Dev\Application\Service\Bench\LoadSeries;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Requests per second and latency percentiles per route, against the running
 * server (tk-fast-bench-harness, ep-fast-benchmark). Before it, Semitexa had
 * no reproducible speed number: the soak's figures were measured once, and the
 * image carries no load tool.
 *
 *   bin/semitexa dev:bench --route=/ --route=/demo/data/relations
 *   bin/semitexa dev:bench --route=/api/products --concurrency=1,16,64 --duration=10 --warmup=2
 *
 * Every route is first warmed with a new connection per request: a keep-alive
 * connection stays on the worker that accepted it, so a warm-up at concurrency 1
 * would leave the others cold, and each window reconnects, so it could land on one.
 * Then each route is run at each concurrency: a warm-up the
 * numbers leave out and --repeat fixed windows, reported as the median with the
 * spread and the machine's load, because one window on a shared machine is one
 * draw and says nothing about how far to trust it. The JSON says which machine and server it ran on, since a
 * number without them compares with nothing.
 */
#[AsCommand(name: 'dev:bench', description: 'Load the running server: req/s and p50/p95/p99 latency per route and concurrency (closed loop, Swoole coroutines)')]
final class DevBenchCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa-dev.bench/v1';

    /** A window is held in memory (every latency sample), so it is bounded. */
    private const MAX_SECONDS = 300;

    /** Connections per level: past this a closed loop measures the generator, not the server. */
    private const MAX_CONCURRENCY = 1024;

    /** Windows per route and level: enough for a spread, bounded so a typo cannot run for hours. */
    private const MAX_REPEAT = 20;

    /** Parallel requests that warm a route, each on its own connection. */
    private const WARM_CONNECTIONS = 16;

    /**
     * Seconds the connection-per-request pass runs, whatever --warmup says: it has
     * reached every worker by then, and each request leaves a socket in TIME_WAIT.
     */
    private const WARM_PASS_MAX_SECONDS = 2.0;

    public function __construct()
    {
        parent::__construct('dev:bench');
    }

    protected function configure(): void
    {
        $this
            ->addOption('route', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A GET path to load (repeat for several)')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Comma-separated concurrency levels', '1,16,64')
            ->addOption('duration', 'd', InputOption::VALUE_REQUIRED, 'Measured seconds per route and level', '10')
            ->addOption('warmup', 'w', InputOption::VALUE_REQUIRED, 'Warm-up seconds per route and per level, left out of the numbers', '2')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Measured windows per route and level: the median is reported, with the spread', '3')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Server to load (default: http://127.0.0.1:$SWOOLE_PORT)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host header to send (a tenant or site bound to a host needs it)')
            ->addOption('accept', null, InputOption::VALUE_REQUIRED, 'Accept header (a JSON route answers 406 to text/html)', 'text/html');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = array_values((array) $input->getOption('route'));
        foreach ($routes as $route) {
            if (!is_string($route) || !str_starts_with($route, '/')) {
                return $this->fail($output, '--route ' . (is_string($route) ? '"' . self::printable($route) . '"' : '') . ' is no path: every route starts with /');
            }
        }
        // Each one is written into the JSON artifact and sent as a request line or header.
        foreach (['--route' => $routes, '--host' => [$input->getOption('host')], '--accept' => [$input->getOption('accept')], '--base-url' => [$input->getOption('base-url')]] as $option => $values) {
            foreach ($values as $value) {
                if (is_string($value) && !self::isText($value)) {
                    return $this->fail($output, "{$option} is not printable UTF-8 text");
                }
            }
        }
        $levels = [];
        foreach (explode(',', (string) $input->getOption('concurrency')) as $part) {
            // A digit string past PHP_INT_MAX casts to PHP_INT_MAX, which the ceiling refuses too.
            if (!ctype_digit(trim($part)) || (int) $part < 1 || (int) $part > self::MAX_CONCURRENCY) {
                return $this->fail($output, '--concurrency "' . self::printable($part) . '" is no whole number from 1 to ' . self::MAX_CONCURRENCY);
            }
            $levels[(int) $part] = (int) $part;
        }
        $levels = array_values($levels);
        $duration = self::seconds($input->getOption('duration'));
        $warmup = self::seconds($input->getOption('warmup'));
        if ($routes === [] || $duration === null || $duration <= 0 || $duration > self::MAX_SECONDS || $warmup === null || $warmup < 0 || $warmup > self::MAX_SECONDS) {
            return $this->fail($output, 'dev:bench needs at least one --route=/path, a --duration above 0 and a --warmup of 0 or more, each a number of seconds up to ' . self::MAX_SECONDS);
        }
        $repeat = (string) $input->getOption('repeat');
        if (!ctype_digit($repeat) || (int) $repeat < 1 || (int) $repeat > self::MAX_REPEAT) {
            return $this->fail($output, '--repeat "' . self::printable($repeat) . '" is no whole number from 1 to ' . self::MAX_REPEAT);
        }
        $repeat = (int) $repeat;
        $base = (string) ($input->getOption('base-url') ?: 'http://127.0.0.1:' . (getenv('SWOOLE_PORT') ?: '9501'));
        $url = parse_url($base);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'http' || !isset($url['host']) || !in_array($url['path'] ?? '', ['', '/'], true) || isset($url['query'])) {
            return $this->fail($output, "--base-url {$base} is no http://host[:port]: the routes are its paths, so it carries none of its own");
        }
        $host = is_string($input->getOption('host')) && $input->getOption('host') !== '' ? (string) $input->getOption('host') : null;
        $headers = ['Accept' => (string) $input->getOption('accept')] + ($host !== null ? ['Host' => $host] : []);
        $generator = new LoadGenerator($url['host'], (int) ($url['port'] ?? 80));

        $warmConnections = max(self::WARM_CONNECTIONS, ...$levels);
        if ($warmup > 0) {
            $warmer = new LoadGenerator($url['host'], (int) ($url['port'] ?? 80), keepAlive: false);
            foreach ($routes as $route) {
                $warmer->run($route, $warmConnections, min($warmup, self::WARM_PASS_MAX_SECONDS), 0.0, $headers);
            }
        }

        $runs = [];
        foreach ($routes as $route) {
            foreach ($levels as $level) {
                $windows = [];
                $loads = [];
                // Once per level: the windows after the first follow one straight away, so they are warm.
                if ($warmup > 0) {
                    $generator->run($route, $level, $warmup, 0.0, $headers);
                }
                for ($window = 0; $window < $repeat; $window++) {
                    $loads[] = self::load();
                    $run = $generator->run($route, $level, $duration, 0.0, $headers);
                    // No response at all is no measurement; 5xx answers are, and are reported.
                    if ($run->requests === ($run->statuses[0] ?? 0)) {
                        return $this->fail($output, "{$route} at concurrency {$level}: no request was answered by {$base}; start the server (bin/semitexa server:start) or pass --base-url");
                    }
                    $windows[] = $run;
                }
                $runs[] = (new LoadSeries($windows, $loads))->toArray();
            }
        }

        $output->writeln((string) json_encode([
            'artifact' => self::ARTIFACT,
            'measured_at' => gmdate('c'),
            'machine' => ['php' => PHP_VERSION, 'swoole' => phpversion('swoole') ?: null, 'cpus' => self::cpus(), 'os' => php_uname('s') . ' ' . php_uname('r')],
            'server' => ['base_url' => $base, 'host' => $host],
            // This process's env: the server's own when --base-url is the local one, not otherwise.
            'cli_env' => ['workers' => getenv('SWOOLE_WORKER_NUM') ?: null, 'app_env' => getenv('APP_ENV') ?: null, 'app_debug' => getenv('APP_DEBUG') ?: null],
            'request' => ['accept' => $headers['Accept']],
            'method' => ['loop' => 'closed', 'concurrency' => $levels, 'warmup_s' => $warmup, 'duration_s' => $duration, 'windows' => $repeat, 'warm_connections' => $warmup > 0 ? $warmConnections : 0],
            'runs' => $runs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private static function seconds(mixed $value): ?float
    {
        return is_string($value) && is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    /** Valid UTF-8 without control characters: safe in a request line, a header and the JSON. */
    private static function isText(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /** A value quoted back in an error, made safe for the JSON that carries it. */
    private static function printable(string $value): string
    {
        return self::isText($value) ? $value : '(not printable text)';
    }

    /** The 1-minute load, or null when the system cannot report it. */
    private static function load(): ?float
    {
        $load = sys_getloadavg();

        return is_array($load) ? round($load[0], 2) : null;
    }

    private static function cpus(): ?int
    {
        $n = function_exists('swoole_cpu_num') ? swoole_cpu_num() : null;

        return is_int($n) && $n > 0 ? $n : null;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln((string) json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return self::FAILURE;
    }
}
