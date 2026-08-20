<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ObservatoryMode;

/**
 * The mode split is a security boundary, so the properties pinned here are the
 * negative ones: prod without the flag stays dark, monitor mode is never
 * "full", and a malformed sample rate journals everything rather than nothing.
 */
final class ObservatoryModeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('SEMITEXA_OBSERVATORY_MODE');
        putenv('SEMITEXA_OBSERVATORY_SAMPLE');
    }

    #[Test]
    public function dev_is_full_and_wins_over_the_flag(): void
    {
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');

        self::assertSame(ObservatoryMode::DEV, ObservatoryMode::resolve());
        self::assertTrue(ObservatoryMode::full());
        self::assertTrue(ObservatoryMode::journals());
        self::assertSame(1.0, ObservatoryMode::sampleRate(), 'dev never samples');
    }

    #[Test]
    public function prod_without_the_flag_is_off(): void
    {
        putenv('APP_ENV=prod');

        self::assertSame(ObservatoryMode::OFF, ObservatoryMode::resolve());
        self::assertFalse(ObservatoryMode::journals());
        self::assertFalse(ObservatoryMode::full());
        self::assertFalse(ObservatoryMode::sampled(), 'off mode must never admit a process');
    }

    #[Test]
    public function monitor_journals_but_is_never_full(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');

        self::assertSame(ObservatoryMode::MONITOR, ObservatoryMode::resolve());
        self::assertTrue(ObservatoryMode::journals());
        self::assertFalse(ObservatoryMode::full(), 'monitor mode is journal-only by design');
    }

    #[Test]
    public function an_unknown_mode_value_is_off(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=verbose');

        self::assertSame(ObservatoryMode::OFF, ObservatoryMode::resolve());
    }

    #[Test]
    public function the_sample_rate_reads_the_env_and_malformed_values_mean_everything(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');

        putenv('SEMITEXA_OBSERVATORY_SAMPLE=0.25');
        self::assertSame(0.25, ObservatoryMode::sampleRate());

        // A monitoring mode that silently records nothing is worse than one
        // that records too much — garbage falls back to 1, not 0.
        foreach (['abc', '5', '-1', ''] as $bad) {
            putenv('SEMITEXA_OBSERVATORY_SAMPLE=' . $bad);
            self::assertSame(1.0, ObservatoryMode::sampleRate(), "rate '$bad' must fall back to 1");
        }
    }

    #[Test]
    public function the_boundary_rates_decide_without_randomness(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');

        putenv('SEMITEXA_OBSERVATORY_SAMPLE=1');
        self::assertTrue(ObservatoryMode::sampled());

        putenv('SEMITEXA_OBSERVATORY_SAMPLE=0');
        self::assertFalse(ObservatoryMode::sampled());
    }
}
