<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Invoke;

/**
 * A route ai:invoke could not find, with the routes the caller most likely
 * meant (tk-invoke-route-not-found-no-candidates).
 *
 * "route not found: GET /x" gave an agent nothing to go on: no route that is
 * close, no word that the path exists for another method, no command to list
 * them. It guessed again, one call per guess.
 */
final class RouteMiss extends \RuntimeException
{
    private const NEAREST = 5;

    /**
     * @param list<string> $nearest     "GET /path", closest first
     * @param list<string> $otherMethods methods the exact path does answer
     */
    private function __construct(string $message, public readonly array $nearest, public readonly array $otherMethods)
    {
        parent::__construct($message);
    }

    /**
     * @param iterable<array<string, mixed>> $routes as route discovery enriches them
     */
    public static function of(string $method, string $path, iterable $routes): self
    {
        $other = [];
        $scored = [];
        foreach ($routes as $route) {
            $candidate = (string) ($route['path'] ?? '');
            if ($candidate === '') {
                continue;
            }
            $methods = array_map('strtoupper', (array) ($route['methods'] ?? [$route['method'] ?? 'GET']));
            if ($candidate === $path) {
                array_push($other, ...array_diff($methods, [$method]));
                continue;
            }
            foreach ($methods as $m) {
                $scored[$m . ' ' . $candidate] = [-self::sharedSegments($path, $candidate), levenshtein($path, $candidate), $m !== $method];
            }
        }
        // The longest shared leading segments first, then the fewest edits, then the same method.
        uasort($scored, static fn (array $a, array $b): int => $a <=> $b);
        $nearest = array_slice(array_keys($scored), 0, self::NEAREST);
        $other = array_values(array_unique($other));

        $message = "route not found: {$method} {$path}.";
        if ($other !== []) {
            $message .= " {$path} answers " . implode(', ', $other) . ', not ' . $method . '.';
        }
        if ($nearest !== []) {
            $message .= ' Nearest: ' . implode(', ', $nearest) . '.';
        }

        return new self($message . ' All routes: bin/semitexa routes:list', $nearest, $other);
    }

    /**
     * The fields the error envelope carries for a miss, none for anything else.
     *
     * @return array<string, mixed>
     */
    public static function fields(\Throwable $e): array
    {
        if (!$e instanceof self) {
            return [];
        }

        return [
            'nearest_routes' => $e->nearest,
            'path_answers_methods' => $e->otherMethods,
            'next_command' => [['cmd' => 'routes:list', 'args' => ['--json'], 'why' => 'every route of the app, with its methods']],
        ];
    }

    private static function sharedSegments(string $a, string $b): int
    {
        $x = explode('/', trim($a, '/'));
        $y = explode('/', trim($b, '/'));
        $n = 0;
        while (isset($x[$n], $y[$n]) && $x[$n] === $y[$n]) {
            $n++;
        }

        return $n;
    }
}
