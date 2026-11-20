<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Intake;

use Semitexa\Core\Environment;
use Semitexa\Core\Log\LogSink;

/**
 * The errors in the application log, grouped into signals.
 *
 * It reads the log the logger writes now, whole, each time: a signal's count
 * is what that file holds, so a second run gives the same answer and there is
 * no cursor to lose or to double-count after. A rotated file is left behind
 * with what it held.
 */
final class AppLogSignals
{
    public const DEFAULT_LOG = 'var/log/app.log';

    public const ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    /**
     * An exception that says a service is down, not that the code is wrong:
     * counted and never filed. Redis refusing connections was most of the
     * error lines in this workspace's log when the intake was written
     * (2026-11-20), and one idea per outage is noise in a backlog.
     */
    public const ENVIRONMENT = [
        'Predis\\Connection\\ConnectionException',
        'Semitexa\\Core\\Redis\\RedisUnavailableException',
    ];

    /** A cheap look before decoding: most lines are not errors. */
    private const ERROR_LINE = '/"level"\s*:\s*"(?:error|critical|alert|emergency)"/i';

    /**
     * Where the logger writes the application log, or null when it writes no
     * file (LOG_SINK=stderr). LOG_FILE is under the project root even when it
     * starts with a slash, as AsyncJsonLogger and logs:app resolve it.
     */
    public static function locate(string $root): ?string
    {
        if (LogSink::fromEnv(Environment::getEnvValue('LOG_SINK')) === LogSink::Stderr) {
            return null;
        }
        $configured = (string) Environment::getEnvValue('LOG_FILE', '');

        return rtrim($root, '/') . '/' . ltrim($configured !== '' ? $configured : self::DEFAULT_LOG, '/');
    }

    /**
     * @return array{signals: list<RuntimeSignal>, lines: int, errors: int, unreadable: int, environment: array<string, int>}
     */
    public static function read(string $path, string $root): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        /** @var array<string, array{channel: string, message: string, exception: ?string, site: ?string, routes: array<string, true>, count: int, first: string, last: string}> $groups */
        $groups = [];
        $lines = 0;
        $errors = 0;
        $unreadable = 0;
        $environment = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $lines++;
                if (preg_match(self::ERROR_LINE, $line) !== 1) {
                    continue;
                }
                $entry = json_decode($line, true);
                if (!is_array($entry)) {
                    $unreadable++;
                    continue;
                }
                // The level may have matched inside a message; the entry's own says.
                if (!in_array(strtolower(self::text($entry['level'] ?? null) ?? ''), self::ERROR_LEVELS, true)) {
                    continue;
                }
                $errors++;
                $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
                $exception = self::text($context['exception'] ?? null);
                if ($exception !== null && in_array($exception, self::ENVIRONMENT, true)) {
                    $environment[$exception] = ($environment[$exception] ?? 0) + 1;
                    continue;
                }
                // The entry's channel: a context "channel" is the caller's own (an SSE channel, say).
                $channel = self::text($entry['channel'] ?? null) ?? '';
                $message = self::text($entry['message'] ?? null) ?? '';
                $site = SignalFingerprint::site(self::text($context['file'] ?? null), $root);
                $at = self::text($entry['timestamp'] ?? null) ?? self::text($entry['datetime'] ?? null) ?? '';
                $fingerprint = SignalFingerprint::of($channel, $message, $exception, $site);

                $group = $groups[$fingerprint] ?? [
                    'channel' => $channel,
                    'message' => SignalFingerprint::normalise($message),
                    'exception' => $exception,
                    'site' => $site,
                    'routes' => [],
                    'count' => 0,
                    'first' => $at,
                    'last' => $at,
                ];
                $group['count']++;
                if ($at !== '' && ($group['first'] === '' || $at < $group['first'])) {
                    $group['first'] = $at;
                }
                if ($at > $group['last']) {
                    $group['last'] = $at;
                }
                $route = self::text($context['route'] ?? null);
                if ($route !== null) {
                    $group['routes'][$route] = true;
                }
                $groups[$fingerprint] = $group;
            }
        } finally {
            fclose($handle);
        }

        $signals = [];
        foreach ($groups as $fingerprint => $group) {
            $routes = array_map('strval', array_keys($group['routes']));
            sort($routes);
            $signals[] = new RuntimeSignal((string) $fingerprint, $group['channel'], $group['message'], $group['exception'], $group['site'], $routes, $group['count'], $group['first'], $group['last']);
        }
        // The loudest first, and a stable order among equals.
        usort($signals, static fn (RuntimeSignal $a, RuntimeSignal $b): int => [$b->count, $a->fingerprint] <=> [$a->count, $b->fingerprint]);
        ksort($environment);

        return ['signals' => $signals, 'lines' => $lines, 'errors' => $errors, 'unreadable' => $unreadable, 'environment' => $environment];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
