<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Bench\LoadGenerator;
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
 * Each route is run at each concurrency: a warm-up the numbers leave out, then
 * a fixed window. The JSON says which machine and server it ran on, since a
 * number without them compares with nothing.
 */
#[AsCommand(name: 'dev:bench', description: 'Load the running server: req/s and p50/p95/p99 latency per route and concurrency (closed loop, Swoole coroutines)')]
final class DevBenchCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa-dev.bench/v1';

    /** A window is held in memory (every latency sample), so it is bounded. */
    private const MAX_SECONDS = 300;

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
            ->addOption('warmup', 'w', InputOption::VALUE_REQUIRED, 'Warm-up seconds before each window, left out of the numbers', '2')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Server to load (default: http://127.0.0.1:$SWOOLE_PORT)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host header to send (a tenant or site bound to a host needs it)')
            ->addOption('accept', null, InputOption::VALUE_REQUIRED, 'Accept header (a JSON route answers 406 to text/html)', 'text/html');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = array_values((array) $input->getOption('route'));
        foreach ($routes as $route) {
            if (!is_string($route) || !str_starts_with($route, '/')) {
                return $this->fail($output, '--route ' . (is_string($route) ? "\"{$route}\"" : '') . ' is no path: every route starts with /');
            }
        }
        $levels = [];
        foreach (explode(',', (string) $input->getOption('concurrency')) as $part) {
            if (!ctype_digit(trim($part)) || (int) $part < 1) {
                return $this->fail($output, "--concurrency \"{$part}\" is no whole number of 1 or more");
            }
            $levels[(int) $part] = (int) $part;
        }
        $levels = array_values($levels);
        $duration = self::seconds($input->getOption('duration'));
        $warmup = self::seconds($input->getOption('warmup'));
        if ($routes === [] || $duration === null || $duration <= 0 || $duration > self::MAX_SECONDS || $warmup === null || $warmup < 0 || $warmup > self::MAX_SECONDS) {
            return $this->fail($output, 'dev:bench needs at least one --route=/path, a --duration above 0 and a --warmup of 0 or more, each a number of seconds up to ' . self::MAX_SECONDS);
        }
        $base = (string) ($input->getOption('base-url') ?: 'http://127.0.0.1:' . (getenv('SWOOLE_PORT') ?: '9501'));
        $url = parse_url($base);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'http' || !isset($url['host']) || !in_array($url['path'] ?? '', ['', '/'], true) || isset($url['query'])) {
            return $this->fail($output, "--base-url {$base} is no http://host[:port]: the routes are its paths, so it carries none of its own");
        }
        $host = is_string($input->getOption('host')) && $input->getOption('host') !== '' ? (string) $input->getOption('host') : null;
        $headers = ['Accept' => (string) $input->getOption('accept')] + ($host !== null ? ['Host' => $host] : []);
        $generator = new LoadGenerator($url['host'], (int) ($url['port'] ?? 80));

        $runs = [];
        foreach ($routes as $route) {
            foreach ($levels as $level) {
                $run = $generator->run($route, $level, $duration, $warmup, $headers);
                // No response at all is no measurement; 5xx answers are, and are reported.
                if ($run->requests === ($run->statuses[0] ?? 0)) {
                    return $this->fail($output, "{$route} at concurrency {$level}: no request was answered by {$base}; start the server (bin/semitexa server:start) or pass --base-url");
                }
                $runs[] = $run->toArray();
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
            'method' => ['loop' => 'closed', 'concurrency' => $levels, 'warmup_s' => $warmup, 'duration_s' => $duration],
            'runs' => $runs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private static function seconds(mixed $value): ?float
    {
        return is_string($value) && is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
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
