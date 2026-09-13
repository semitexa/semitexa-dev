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
     * @param array{file: string, line: int, function: string, class: ?string, called_from?: string}|null $culprit
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

        foreach ($frames as $index => $frame) {
            if (self::isPlumbing($frame)) {
                continue;
            }

            // A backtrace frame names the CALLEE in class/function and the CALL
            // SITE in file/line, so rendering one frame as a single location
            // showed the handler's name beside AiInvokeCommand.php. The line
            // INSIDE this function is the call site of the frame below it —
            // and for the innermost frame, the throw itself. Raised in review
            // of dev#84.
            // A frame invoked BY AN INTERNAL FUNCTION carries no location at
            // all — a closure handed to array_map, a comparator inside usort,
            // a destructor. `normalise()` fills those with `''` and `0`, and
            // taking the line from one produced a culprit at `:0`, a location
            // that exists nowhere and that the source reader cannot open. So
            // an unusable location is not used: the throw site stands in,
            // which is a real place the failure genuinely passed through.
            // Raised in review of dev#84.
            $inner = $index > 0 ? $frames[$index - 1] : null;
            $inside = $inner !== null && self::hasLocation($inner)
                ? ['file' => $inner['file'], 'line' => $inner['line']]
                : ['file' => $throwFile, 'line' => $throwLine];

            $culprit = [
                'file' => $inside['file'],
                'line' => $inside['line'],
                'function' => $frame['function'],
                'class' => $frame['class'],
                // Kept because it is a real location too — where this function
                // was called from — and losing it would hide the caller. Null
                // rather than `:0` when this frame has none: an absent caller
                // is a fact, and a fabricated one sends a reader to a file
                // that has no such line.
                'called_from' => self::hasLocation($frame) ? $frame['file'] . ':' . $frame['line'] : null,
            ];
            break;
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
     * Does this frame name a place a reader can open?
     *
     * @param array{file: string, line: int, function: string, class: ?string} $frame
     */
    private static function hasLocation(array $frame): bool
    {
        return $frame['file'] !== '' && $frame['line'] > 0;
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
     * @param array{file: string, line: int, function: string, class: ?string, called_from?: string} $culprit
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
