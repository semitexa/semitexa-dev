<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * The journal-process a coroutine is currently inside, between its root begin
 * and root end.
 *
 * Same storage idea as {@see TraceContext}, deliberately simpler: the root span
 * begins and ends in the same coroutine (RouteExecutor::execute runs both), so
 * no ancestor walk is needed — one slot per coroutine plus a process-local
 * fallback for CLI and tests.
 *
 * Nesting is counted, not named: an internal re-dispatch opens a second
 * root-named span inside the same coroutine, and it is nested work, not a
 * second process. Only the outermost close returns the record, so the journal
 * gets exactly one begin and one end per real process.
 */
final class ObservatoryContext
{
    private const KEY = '__semitexa_observatory_process';

    /** @var array{record: array<string, mixed>, open: int}|null */
    private static ?array $fallback = null;

    /** @param array<string, mixed> $record */
    public static function open(array $record): void
    {
        self::put(['record' => $record, 'open' => 1]);
    }

    /** True when a process is already open here — the caller is nested work. */
    public static function nest(): bool
    {
        $slot = self::get();
        if ($slot === null) {
            return false;
        }

        $slot['open']++;
        self::put($slot);

        return true;
    }

    /**
     * Close one level; returns the process record only when the OUTERMOST level
     * closed, null while still nested (or when nothing was open — an unbalanced
     * end, which the journal simply ignores).
     *
     * @return array<string, mixed>|null
     */
    public static function close(): ?array
    {
        $slot = self::get();
        if ($slot === null) {
            return null;
        }

        $slot['open']--;
        if ($slot['open'] > 0) {
            self::put($slot);

            return null;
        }

        self::clear();

        return $slot['record'];
    }

    public static function reset(): void
    {
        self::$fallback = null;
        if (self::inCoroutine()) {
            self::clear();
        }
    }

    /** @return array{record: array<string, mixed>, open: int}|null */
    private static function get(): ?array
    {
        if (!self::inCoroutine()) {
            return self::$fallback;
        }

        $context = \Swoole\Coroutine::getContext();
        $slot = $context instanceof \ArrayObject ? ($context[self::KEY] ?? null) : null;

        return is_array($slot) ? $slot : null;
    }

    /** @param array{record: array<string, mixed>, open: int} $slot */
    private static function put(array $slot): void
    {
        if (!self::inCoroutine()) {
            self::$fallback = $slot;

            return;
        }

        $context = \Swoole\Coroutine::getContext();
        if ($context instanceof \ArrayObject) {
            $context[self::KEY] = $slot;
        }
    }

    private static function clear(): void
    {
        if (!self::inCoroutine()) {
            self::$fallback = null;

            return;
        }

        $context = \Swoole\Coroutine::getContext();
        if ($context instanceof \ArrayObject) {
            unset($context[self::KEY]);
        }
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() > 0;
    }
}
