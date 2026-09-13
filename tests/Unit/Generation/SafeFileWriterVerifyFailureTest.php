<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationExitCode;
use Semitexa\Dev\Application\Service\Generation\Writer\SafeFileWriter;
use Symfony\Component\Console\Command\Command;

/**
 * The syntax check runs `php -l` per created file, and the runner reports its
 * OWN failures — a timeout, a subprocess that would not start — separately from
 * what the file says. Those tell you nothing about the generated code, so they
 * are `skipped`, and GenerationExitCode deliberately treats `skipped` as
 * success.
 *
 * Which made the order matter: a real parse error found in the first file
 * followed by a runner failure on the second returned `skipped` and discarded
 * the error, and the command exited 0 having written code known not to parse.
 * Raised in review of dev#83.
 */
final class SafeFileWriterVerifyFailureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-verify-' . uniqid('', true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->root);
    }

    private function file(string $path): PlannedFile
    {
        return new PlannedFile($path, '<?php echo 1;', FileType::PhpClass);
    }

    #[Test]
    public function a_parse_error_found_before_a_runner_failure_is_not_thrown_away(): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing', new ScriptedRunner([
            ['exit' => 255, 'output' => 'PHP Parse error: syntax error, unexpected token ";" in First.php on line 3'],
            ['exit' => 0, 'output' => '', 'failure' => 'timed out after 30s'],
        ]));

        $result = $writer->write([$this->file('First.php'), $this->file('Second.php')]);

        self::assertSame('fail', $result->verify['status'], 'a known parse error outranks a check that could not run');
        self::assertCount(1, $result->verify['errors']);
        self::assertSame('First.php', $result->verify['errors'][0]['file']);
        self::assertStringContainsString('stopped early', (string) ($result->verify['reason'] ?? ''));
        self::assertSame(
            Command::FAILURE,
            GenerationExitCode::forResult($result),
            'exiting 0 here is how invalid generated code reached a caller as success',
        );
    }

    /**
     * `checked` is a count of files LOOKED AT, and the early return reported
     * count($errors) — the number of problems. One clean file plus one parse
     * error plus a runner failure said "checked 1". Raised in review of dev#83.
     */
    #[Test]
    public function checked_counts_the_files_looked_at_not_the_problems_found(): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing', new ScriptedRunner([
            ['exit' => 0, 'output' => 'No syntax errors detected'],
            ['exit' => 255, 'output' => 'PHP Parse error: syntax error'],
            ['exit' => 0, 'output' => '', 'failure' => 'timed out after 30s'],
        ]));

        $result = $writer->write([$this->file('A.php'), $this->file('B.php'), $this->file('C.php')]);

        self::assertSame('fail', $result->verify['status']);
        self::assertSame(2, $result->verify['checked'], 'two files were checked before the runner broke');
        self::assertCount(1, $result->verify['errors']);
    }

    #[Test]
    public function a_clean_run_counts_every_file_it_checked(): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing', new ScriptedRunner([
            ['exit' => 0, 'output' => ''],
            ['exit' => 0, 'output' => ''],
        ]));

        $result = $writer->write([$this->file('A.php'), $this->file('B.php')]);

        self::assertSame('pass', $result->verify['status']);
        self::assertSame(2, $result->verify['checked']);
    }

    /**
     * The other order is the one the distinction exists for: nothing was found
     * before the runner broke, so there is nothing to report against the files.
     */
    #[Test]
    public function a_runner_failure_with_nothing_found_yet_is_still_skipped(): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing', new ScriptedRunner([
            ['exit' => 0, 'output' => '', 'failure' => 'could not spawn a subprocess'],
        ]));

        $result = $writer->write([$this->file('First.php')]);

        self::assertSame('skipped', $result->verify['status']);
        self::assertSame([], $result->verify['errors']);
        self::assertSame(
            Command::SUCCESS,
            GenerationExitCode::forResult($result),
            'a check that could not run is not evidence of a problem',
        );
    }

    #[Test]
    public function a_clean_batch_still_passes(): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing', new ScriptedRunner([
            ['exit' => 0, 'output' => 'No syntax errors detected'],
        ]));

        $result = $writer->write([$this->file('First.php')]);

        self::assertSame('pass', $result->verify['status']);
        self::assertSame(Command::SUCCESS, GenerationExitCode::forResult($result));
    }
}

/** Hands back a prepared outcome per call, in order. */
final class ScriptedRunner implements ProcessRunner
{
    /** @param list<array{exit: int, output: string, failure?: string}> $outcomes */
    public function __construct(private array $outcomes = []) {}

    public function run(array $command, string $cwd): array
    {
        return array_shift($this->outcomes) ?? ['exit' => 0, 'output' => ''];
    }
}
