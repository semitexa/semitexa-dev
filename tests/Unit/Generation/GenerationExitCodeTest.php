<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationExitCode;

/**
 * Every make command used to return SUCCESS whatever the writer said. A
 * conflict was a warning printed to a human, a refused path and a failed
 * `php -l` were nothing at all — so a script, a CI step, or the `make`
 * dispatcher (which stops on the first non-zero step) could not tell a
 * finished generation from one that wrote nothing.
 *
 * The exit code answers one question: did the command do all of what was
 * asked? Which files landed and whether they verify stay separate facts in
 * the envelope, where a reader can act on each.
 */
final class GenerationExitCodeTest extends TestCase
{
    private function outcome(
        string $status,
        array $created = [],
        array $conflicts = [],
        ?array $verify = null,
        ?array $lint = null,
        ?array $errors = null,
    ): GenerationResult {
        return new GenerationResult(
            command: 'make:thing',
            status: $status,
            created: $created,
            conflicts: $conflicts,
            verify: $verify,
            lint: $lint,
            errors: $errors,
        );
    }

    #[Test]
    public function a_complete_generation_succeeds(): void
    {
        $result = $this->outcome('success', created: ['a.php'], verify: ['status' => 'pass', 'checked' => 1, 'errors' => []]);

        self::assertSame(0, GenerationExitCode::forResult($result));
    }

    #[Test]
    public function a_generation_with_nothing_to_verify_succeeds(): void
    {
        $result = $this->outcome('success', created: ['README.md']);

        self::assertSame(0, GenerationExitCode::forResult($result));
    }

    #[Test]
    public function a_conflict_is_a_failure_because_nothing_was_written(): void
    {
        $result = $this->outcome('conflict', conflicts: ['a.php']);

        self::assertSame(1, GenerationExitCode::forResult($result), 'the caller asked for a file and did not get one');
    }

    #[Test]
    public function a_partial_write_is_a_failure(): void
    {
        $result = $this->outcome('partial', created: ['a.php'], conflicts: ['b.php']);

        self::assertSame(1, GenerationExitCode::forResult($result), 'some of what was asked for did not happen');
    }

    #[Test]
    public function a_refused_path_is_a_failure(): void
    {
        $result = $this->outcome('rejected', errors: [['path' => '../x', 'reason' => 'traversal', 'detail' => 'nope']]);

        self::assertSame(1, GenerationExitCode::forResult($result));
    }

    #[Test]
    public function an_io_error_is_a_failure(): void
    {
        $result = $this->outcome('error', errors: [['path' => 'a.php', 'reason' => 'write_failed', 'detail' => 'disk full']]);

        self::assertSame(1, GenerationExitCode::forResult($result));
    }

    #[Test]
    public function code_that_does_not_parse_fails_even_though_it_was_written(): void
    {
        $result = $this->outcome(
            'success',
            created: ['a.php'],
            verify: ['status' => 'fail', 'checked' => 1, 'errors' => [['file' => 'a.php', 'message' => 'syntax error']]],
        );

        self::assertSame(1, GenerationExitCode::forResult($result), 'a generator that emits unparseable code has failed');
    }

    #[Test]
    public function failing_post_write_lint_fails_the_command(): void
    {
        $result = $this->outcome(
            'success',
            created: ['a.php'],
            verify: ['status' => 'pass', 'checked' => 1, 'errors' => []],
            lint: ['status' => 'fail', 'checks' => ['di' => ['status' => 'fail', 'summary' => '1 error']]],
        );

        self::assertSame(1, GenerationExitCode::forResult($result));
    }

    #[Test]
    public function a_check_that_could_not_run_does_not_fail_the_command(): void
    {
        $result = $this->outcome(
            'success',
            created: ['a.php'],
            verify: ['status' => 'skipped', 'checked' => 0, 'errors' => []],
            lint: ['status' => 'skipped', 'checks' => []],
        );

        self::assertSame(0, GenerationExitCode::forResult($result), 'skipped is not failed; absence of a check is not evidence');
    }

    #[Test]
    public function a_dry_run_is_always_a_success(): void
    {
        // Nothing was asked to be written, so nothing can have failed to write.
        // 'dry_run' is the status the make commands actually emit — VERIFIED
        // 2026-09-12 against make:service, after this test first asserted an
        // invented one.
        $result = $this->outcome('dry_run', created: []);

        self::assertSame(0, GenerationExitCode::forResult($result));
    }
}
