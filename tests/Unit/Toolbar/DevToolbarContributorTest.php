<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Toolbar;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Toolbar\DevToolbarContributor;

/**
 * Only explicit values are set: an unset variable falls back to the cached
 * .env values, so "unset" is not something this process can arrange.
 */
final class DevToolbarContributorTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'SEMITEXA_DEV_TOOLBAR', 'SEMITEXA_OBSERVATORY_MODE'] as $key) {
            $this->saved[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    #[Test]
    public function it_is_on_in_dev_and_off_when_switched_off(): void
    {
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_DEV_TOOLBAR=1');
        self::assertTrue(DevToolbarContributor::enabled());

        putenv('SEMITEXA_DEV_TOOLBAR=0');
        self::assertFalse(DevToolbarContributor::enabled());
    }

    #[Test]
    public function production_monitor_mode_never_gets_the_toolbar(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_DEV_TOOLBAR=1');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');

        self::assertFalse(DevToolbarContributor::enabled());
    }
}
