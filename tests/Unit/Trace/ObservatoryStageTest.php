<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ObservatoryStage;

final class ObservatoryStageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-stage-' . uniqid();
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('SEMITEXA_OBSERVATORY_DIR');
        putenv('SEMITEXA_OBSERVATORY_MODE');
        @unlink($this->dir . '/stage.on');
        @rmdir($this->dir);
    }

    #[Test]
    public function off_by_default_on_then_off_again(): void
    {
        self::assertFalse(ObservatoryStage::isOn());
        self::assertTrue(ObservatoryStage::set(true));
        self::assertTrue(ObservatoryStage::isOn());
        self::assertFileExists($this->dir . '/stage.on');
        self::assertTrue(ObservatoryStage::set(false));
        self::assertFalse(ObservatoryStage::isOn());
        self::assertTrue(ObservatoryStage::set(false), 'switching off twice is not an error');
    }

    #[Test]
    public function the_flag_lapses_after_its_ttl_and_is_renewed_by_setting_it_again(): void
    {
        ObservatoryStage::set(true);
        touch(ObservatoryStage::path(), time() - ObservatoryStage::TTL_SECONDS - 1);
        self::assertFalse(ObservatoryStage::isOn(), 'a flag older than the TTL is off, file or no file');

        ObservatoryStage::set(true);
        self::assertTrue(ObservatoryStage::isOn(), 'setting it again renews the file');
    }

    #[Test]
    public function the_flag_is_ignored_outside_dev_even_when_the_file_exists(): void
    {
        ObservatoryStage::set(true);
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');

        self::assertFalse(ObservatoryStage::isOn(), 'monitor mode journals lifecycles, it never records request internals');
    }
}
