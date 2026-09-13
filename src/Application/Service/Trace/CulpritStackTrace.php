<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * A thrown error, rearranged so the line that caused it is the first thing read.
 *
 * `getTraceAsString()` truncated to six lines is what this replaces, and those
 * six lines are usually the framework getting to the caller's code rather than
 * the caller's code itself: container resolution, the console runner, the
 * pipeline. The frame that matters sits below the cut, so the reader scrolls
 * past plumbing to find their own file — or never sees it.
 *
 * ## What counts as plumbing
 *
 * Anything under `vendor/`, plus the named namespaces that exist to CALL other
 * code: the container, the pipeline, the console. Deliberately NOT "anything
 * under packages/semitexa-*" — this framework is developed in this repository,
 * so a bug in `semitexa/cache` is exactly the culprit somebody wants named.
 * Plumbing is a role, not an address.
 *
 * Consecutive plumbing frames collapse into one line saying how many were
 * folded, so the shape of the call is still visible without the noise.
 */
final readonly class CulpritStackTrace
{
    /**
     * Namespaces whose job is to call other code. A frame here is never the
     * answer to "what broke".
     */
    private const PLUMBING_NAMESPACES = [
        'Semitexa\\Core\\Container\\',
        'Semitexa\\Core\\Pipeline\\',
        'Semitexa\\Core\\Console\\',
        'Semitexa\\Core\\Server\\',
        'Symfony\\Component\\Console\\',
    ];

    /**
     * @param array{file: string, line: int, function: string, class: ?string}|null $culprit
     * @param list<array<string, mixed>> $frames
     * @param list<string> $source
     */
    private function __construct(
        public string $class,
        public string $message,
        public ?array $culprit,
        public array $frames,
        public array $source,
    ) {
    }

    public static function of(\Throwable $e, ?SourceSliceReader $reader = null): self
    {
        return self::fromFrames(
            get_class($e),
            $e->getMessage(),
            self::normalise($e->getTrace()),
            $e->getFile(),
            $e->getLine(),
            $reader,
        );
    }

    /**
     * The same thing from raw frames.
     *
     * A separate entry point because `Exception::getTrace()` is final: a test
     * cannot hand this class a stack to reason about through a throwable, and a
     * formatter whose only input is un-fakeable is a formatter nobody can test.
     *
     * @param list<array{file: string, line: int, function: string, class: ?string}> $frames
     */
    public static function fromFrames(
        string $class,
        string $message,
        array $frames,
        string $throwFile,
        int $throwLine,
        ?SourceSliceReader $reader = null,
    ): self {
        $culprit = null;

        foreach ($frames as $frame) {
            if (!self::isPlumbing($frame)) {
                $culprit = $frame;
                break;
            }
        }

        // Everything was plumbing — a failure INSIDE the machinery. The throw
        // site is then the most useful thing there is, and saying "no culprit"
        // would be hiding the only answer available.
        $culprit ??= [
            'file' => $throwFile,
            'line' => $throwLine,
            'function' => '(throw site)',
            'class' => null,
        ];

        return new self(
            $class,
            $message,
            $culprit,
            self::collapse($frames),
            self::sourceFor($culprit, $reader),
        );
    }

    /**
     * @return array{class: string, message: string, culprit: array<string, mixed>|null, frames: list<array<string, mixed>>, source: list<string>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'message' => $this->message,
            'culprit' => $this->culprit,
            'frames' => $this->frames,
            'source' => $this->source,
        ];
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @return list<array{file: string, line: int, function: string, class: ?string}>
     */
    private static function normalise(array $raw): array
    {
        $out = [];

        foreach ($raw as $frame) {
            $out[] = [
                'file' => is_string($frame['file'] ?? null) ? $frame['file'] : '',
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : 0,
                'function' => is_string($frame['function'] ?? null) ? $frame['function'] : '',
                'class' => is_string($frame['class'] ?? null) ? $frame['class'] : null,
            ];
        }

        return $out;
    }

    /** @param array{file: string, line: int, function: string, class: ?string} $frame */
    private static function isPlumbing(array $frame): bool
    {
        if ($frame['file'] !== '' && str_contains($frame['file'], '/vendor/')) {
            return true;
        }

        $class = $frame['class'];
        if ($class === null) {
            return false;
        }

        foreach (self::PLUMBING_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{file: string, line: int, function: string, class: ?string}> $frames
     * @return list<array<string, mixed>>
     */
    private static function collapse(array $frames): array
    {
        $out = [];
        $run = 0;

        foreach ($frames as $frame) {
            if (self::isPlumbing($frame)) {
                $run++;
                continue;
            }

            if ($run > 0) {
                $out[] = ['collapsed' => $run, 'label' => self::plural($run) . ' of framework plumbing'];
                $run = 0;
            }

            $out[] = $frame;
        }

        if ($run > 0) {
            $out[] = ['collapsed' => $run, 'label' => self::plural($run) . ' of framework plumbing'];
        }

        return $out;
    }

    private static function plural(int $n): string
    {
        return $n === 1 ? '1 frame' : $n . ' frames';
    }

    /**
     * @param array{file: string, line: int, function: string, class: ?string} $culprit
     * @return list<string>
     */
    private static function sourceFor(array $culprit, ?SourceSliceReader $reader): array
    {
        if ($reader === null || $culprit['class'] === null) {
            return [];
        }

        $slice = $reader->slice($culprit['class'], $culprit['function']);

        return $slice === null ? [] : $slice->lines;
    }
}
