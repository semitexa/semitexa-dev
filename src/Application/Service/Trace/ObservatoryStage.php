<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * Stage mode: record a phase breakdown for EVERY request, not only the ones
 * carrying `?__trace=1`.
 *
 * The live panel draws each request travelling through the pipeline. Without
 * a buffer it only knows begin and end, so the particle glides evenly and the
 * stage nodes stay dark. With stage mode on, every request opens a trace
 * buffer, its end line in the journal carries {@see PhaseSummary}, and the
 * picture shows where the time actually went — auth, hydration, listeners,
 * handler, render — for the audience to see.
 *
 * ## Why a flag file, not env
 *
 * A presenter flips it from the page and expects the next request to obey.
 * Env is read at worker start; a file in the observatory dir is seen by every
 * worker on the next request, survives nothing it should not (it lives beside
 * the journal, which is dev-local state), and costs one stat per request.
 *
 * ## What it does NOT do
 *
 * Stage-mode buffers are never flushed to var/trace unless the request also
 * carried the marker: a trace file per request would bury the one somebody
 * asked for, and the journal line is all the panel needs. Dev only, like
 * every other recording tier — {@see ObservatoryMode::full()} gates it.
 */
final class ObservatoryStage
{
    private const FLAG = 'stage.on';

    /**
     * A switch nobody remembers is a cost nobody measures: a flag left on after
     * a demo would open a buffer for every request on that machine for weeks.
     * It lapses on its own; flipping it again from the page renews it.
     */
    public const TTL_SECONDS = 12 * 3600;

    public static function isOn(): bool
    {
        if (!ObservatoryMode::full()) {
            return false;
        }

        $path = self::path();
        // A long-lived worker keeps PHP's stat cache between requests; without
        // clearing it a flag flipped by another worker would stay invisible.
        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        return $mtime !== false && (time() - $mtime) < self::TTL_SECONDS;
    }

    public static function set(bool $on): bool
    {
        try {
            $path = self::path();
            if ($on) {
                $dir = dirname($path);
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    return false;
                }

                return @file_put_contents($path, date('c') . "\n") !== false;
            }

            return !is_file($path) || @unlink($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function path(): string
    {
        return ObservatoryJournal::dir() . '/' . self::FLAG;
    }
}
