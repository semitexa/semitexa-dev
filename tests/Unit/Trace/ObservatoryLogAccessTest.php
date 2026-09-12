<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ObservatoryLogAccess;

/**
 * The second gate, and the negative cases are the whole point of it.
 *
 * The panel gate already lets an operator with a token open this surface from
 * anywhere. These tests pin that having earned the panel does NOT earn the log
 * lines, because the two are different kinds of content: measurement the
 * framework made about itself, versus whatever the application put in a
 * context array.
 */
final class ObservatoryLogAccessTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        putenv('APP_ENV');
        putenv('SEMITEXA_OBSERVATORY_MODE');
        putenv('SEMITEXA_OBSERVATORY_TOKEN');
    }

    #[Test]
    public function dev_reads_its_own_logs(): void
    {
        putenv('APP_ENV=dev');

        self::assertTrue((new ObservatoryLogAccess())->allows());
    }

    #[Test]
    public function monitor_mode_earns_the_panel_but_not_the_lines(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');

        self::assertFalse(
            (new ObservatoryLogAccess())->allows(),
            'monitor mode is reachable from anywhere with one shared token; log context is not for that audience',
        );
    }

    #[Test]
    public function a_token_does_not_buy_the_lines_either(): void
    {
        // The token is what the panel gate checks. This gate must not consult
        // it at all, or it becomes the same gate wearing a second name.
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        putenv('SEMITEXA_OBSERVATORY_TOKEN=a-perfectly-valid-token');

        self::assertFalse((new ObservatoryLogAccess())->allows());
    }

    #[Test]
    public function production_without_the_flag_is_refused(): void
    {
        putenv('APP_ENV=prod');

        self::assertFalse((new ObservatoryLogAccess())->allows());
    }

    #[Test]
    public function a_refusal_always_says_why(): void
    {
        // An empty log list and a withheld one look identical, and the first
        // reads as "nothing went wrong here" — the most misleading thing this
        // surface could say. So every refusal carries a sentence.
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        $monitor = (new ObservatoryLogAccess())->refusalReason();

        putenv('SEMITEXA_OBSERVATORY_MODE');
        $off = (new ObservatoryLogAccess())->refusalReason();

        self::assertStringContainsString('monitor mode', $monitor);
        self::assertStringContainsString('ai:ask logs', $monitor, 'a refusal that names no alternative is an obstacle');
        self::assertNotSame('', $off);
        self::assertNotSame($monitor, $off, 'the two refusals have different remedies and must not read alike');
    }
}
