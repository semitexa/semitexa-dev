<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\AsyncJsonLogger;
use Semitexa\Core\Log\LogOrigin;
use Semitexa\Dev\Application\Service\Trace\LogOriginAttribution;
use Semitexa\Dev\Application\Service\Trace\ObservatoryContext;
use Semitexa\Dev\Application\Service\Trace\TraceBuffer;
use Semitexa\Dev\Application\Service\Trace\TraceContext;

/**
 * The seam, end to end, through the logger that actually writes the file.
 *
 * The unit tests either side of this one prove that core asks and that dev can
 * answer. Neither proves the two are connected — and a seam whose halves are
 * each correct while nothing joins them is the failure this kind of change
 * invites. So this writes a real line with the real logger and reads it back
 * off disk.
 *
 * Manual observation was tried first and could not do the job: healthy traffic
 * writes no log lines at all, so there was nothing to look at. That is a good
 * property of the application and a bad way to verify a logger.
 */
final class LogLineCarriesItsBlockTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private string $relativeLogFile = '';
    private string|false $previousLogFile = false;
    private string $logFile = '';

    protected function setUp(): void
    {
        // RELATIVE on purpose. AsyncJsonLogger::flush() builds its path as
        // projectRoot . '/' . ltrim($logFile, '/'), so an absolute LOG_FILE is
        // silently glued under the project root and the line lands somewhere
        // nobody is looking — which is how the first version of this test
        // failed, with the logger working perfectly.
        $this->relativeLogFile = 'var/log/test-log-origin-' . bin2hex(random_bytes(6)) . '.log';
        $this->logFile = getcwd() . '/' . $this->relativeLogFile;
        $this->previousLogFile = getenv('LOG_FILE');
        putenv('LOG_FILE=' . $this->relativeLogFile);
        putenv('LOG_LEVEL=debug');
    }

    protected function tearDown(): void
    {
        LogOrigin::resolveWith(null);
        ObservatoryContext::reset();
        TraceContext::resetFallback();
        // Restore, not unset: the bootstrap points LOG_FILE at the test log, and an
        // unset here would send every later test's output back into app.log.
        putenv($this->previousLogFile === false ? 'LOG_FILE' : 'LOG_FILE=' . $this->previousLogFile);
        putenv('LOG_LEVEL');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /** @return array<string, mixed> */
    private function writeAndReadBack(string $message): array
    {
        // The logger is container-managed, so it is built the way the container
        // builds it: no constructor, dependencies set as properties. Environment
        // is NOT — it has a real constructor and its own factory reads the
        // process, which is exactly the state under test here.
        $logger = self::createWithDependencies(AsyncJsonLogger::class, [
            'environment' => Environment::create(),
        ]);

        $logger->error($message, ['probe' => true]);

        self::assertFileExists($this->logFile, 'the logger wrote nothing at all');
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile))));
        $entry = json_decode((string) end($lines), true);
        self::assertIsArray($entry);

        return $entry;
    }

    #[Test]
    public function a_line_written_inside_a_block_says_which_block(): void
    {
        ObservatoryContext::open(['id' => 'p-17-abcdef', 'kind' => 'http']);
        $buffer = new TraceBuffer(startedAt: (float) hrtime(true), rootCid: 0, rootSpan: 'request');
        TraceContext::begin($buffer);
        $buffer->enter(0, 'request', 0.0);
        $buffer->enter(0, 'pipeline.handler', 0.0);
        LogOriginAttribution::install();

        $entry = $this->writeAndReadBack('something went wrong in the handler');

        self::assertSame('p-17-abcdef', $entry['process'] ?? null);
        self::assertSame('pipeline.handler', $entry['block'] ?? null);
    }

    #[Test]
    public function a_line_written_outside_everything_carries_no_origin_at_all(): void
    {
        // A worker booting, a CLI command, a queue consumer. These lines are not
        // less important; they have no block to belong to, and the entry must
        // not sprout empty keys saying so.
        LogOriginAttribution::install();

        $entry = $this->writeAndReadBack('worker is starting');

        self::assertArrayNotHasKey('process', $entry);
        self::assertArrayNotHasKey('block', $entry);
        self::assertSame('worker is starting', $entry['message'] ?? null);
    }

    #[Test]
    public function the_line_still_gets_written_when_the_resolver_explodes(): void
    {
        LogOrigin::resolveWith(static function (): array {
            throw new \RuntimeException('the observer broke');
        });

        $entry = $this->writeAndReadBack('the application is fine');

        self::assertSame('the application is fine', $entry['message'] ?? null);
        self::assertArrayNotHasKey('block', $entry);
    }
}
