<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Environment;

/**
 * The one place that answers "how much may the Observatory see here?".
 *
 * Three tiers, resolved from the environment on every ask (env is the truth,
 * caching it would survive a worker past a config change):
 *
 * - `dev`     — APP_ENV=dev. Everything: journal, trace files, deep context,
 *               replay, an open panel. Unchanged from before this class.
 * - `monitor` — any other APP_ENV with SEMITEXA_OBSERVATORY_MODE=monitor.
 *               Journal-only production observability: lifecycle lines
 *               (begin/end, kind, name, duration) for every process, nothing
 *               else. No trace buffers, no query recording, no payload
 *               snapshots, no replay — those read request internals and run
 *               code, which is exactly what must not quietly exist on prod.
 * - `off`     — everything else. The default outside dev, same as before.
 *
 * APP_ENV=dev wins over the env flag on purpose: a dev stack that sets
 * SEMITEXA_OBSERVATORY_MODE (say, copied from a prod .env) must not silently
 * LOSE tracing — narrowing dev would break the tool where it is used most.
 *
 * ## Sampling
 *
 * In monitor mode SEMITEXA_OBSERVATORY_SAMPLE (0..1, default 1) decides per
 * PROCESS whether its lifecycle is journaled, so a busy prod box can pay for
 * a representative stream instead of every request. The decision is made once
 * at begin and carried on the open slot — a sampled-out begin must also
 * swallow its end, or the reader would see ends without begins and misread
 * the live picture. Dev never samples: the journal there is the live panel's
 * ground truth and a dropped line is a lie in the debugger.
 */
final class ObservatoryMode
{
    public const OFF = 'off';
    public const MONITOR = 'monitor';
    public const DEV = 'dev';

    public static function resolve(): string
    {
        if (Environment::getEnvValue('APP_ENV') === 'dev') {
            return self::DEV;
        }

        $mode = Environment::getEnvValue('SEMITEXA_OBSERVATORY_MODE');

        return is_string($mode) && strtolower(trim($mode)) === self::MONITOR
            ? self::MONITOR
            : self::OFF;
    }

    /** Whether process lifecycles are journaled at all (dev or monitor). */
    public static function journals(): bool
    {
        return self::resolve() !== self::OFF;
    }

    /**
     * Whether the full instrument is available: trace recording, deep context,
     * replay, and the panel without its gate. Dev only — monitor mode is
     * deliberately journal-only.
     */
    public static function full(): bool
    {
        return self::resolve() === self::DEV;
    }

    /**
     * Per-process sampling decision for the journal. Only monitor mode ever
     * samples below 1; a malformed or out-of-range env value falls back to 1
     * (journal everything) rather than 0 — a monitoring mode that silently
     * records nothing is worse than one that records too much.
     */
    public static function sampled(): bool
    {
        $rate = self::sampleRate();
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand(1, 1_000_000) <= (int) round($rate * 1_000_000);
    }

    public static function sampleRate(): float
    {
        $mode = self::resolve();
        if ($mode === self::DEV) {
            return 1.0;
        }
        if ($mode !== self::MONITOR) {
            return 0.0;
        }

        $raw = Environment::getEnvValue('SEMITEXA_OBSERVATORY_SAMPLE');
        if (!is_string($raw) || trim($raw) === '' || !is_numeric(trim($raw))) {
            return 1.0;
        }

        $rate = (float) trim($raw);

        return ($rate < 0.0 || $rate > 1.0) ? 1.0 : $rate;
    }
}
