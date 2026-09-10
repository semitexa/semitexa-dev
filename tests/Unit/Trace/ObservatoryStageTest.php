<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryStageHandler;
use Semitexa\Dev\Application\Payload\Request\ObservatoryStagePayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
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
        CurrentRequestStore::clear();
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

    /**
     * PayloadHydrator fills setters from the query string whatever the method,
     * so a plain link to `/__observatory/stage?on=1` used to switch recording
     * on for the whole stack. A read must not write. Raised in review of
     * semitexa-dev#78.
     */
    #[Test]
    public function a_get_carrying_on_1_reads_the_flag_without_setting_it(): void
    {
        $this->request('GET');

        $body = json_decode((string) $this->callStage('1')->getContent(), true);

        self::assertFalse(ObservatoryStage::isOn(), 'a GET must never flip the switch');
        self::assertFalse($body['stage']);
        self::assertNull($body['applied'], 'nothing was applied, and the answer says so');
    }

    #[Test]
    public function a_post_still_sets_and_clears_it(): void
    {
        $this->request('POST');

        self::assertTrue(json_decode((string) $this->callStage('1')->getContent(), true)['stage']);
        self::assertTrue(ObservatoryStage::isOn());

        self::assertFalse(json_decode((string) $this->callStage('0')->getContent(), true)['stage']);
        self::assertFalse(ObservatoryStage::isOn());
    }

    private function callStage(string $on): ResourceResponse
    {
        $handler = new ObservatoryStageHandler();
        (new \ReflectionProperty(ObservatoryStageHandler::class, 'gate'))->setValue($handler, new ObservatoryPanelGate());
        $payload = new ObservatoryStagePayload();
        $payload->setOn($on);

        return $handler->handle($payload, new ResourceResponse());
    }

    private function request(string $method): void
    {
        CurrentRequestStore::set(new Request($method, '/__observatory/stage', [], ['on' => '1'], [], [], []));
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
