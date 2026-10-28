<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Phpstan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\ChangedLineTypeCheck;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunResult;
use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;

/**
 * phpstan_di ran at level 0 and passed a call that left out an argument — a
 * TypeError on the path meant only to warn (2026-10-21). The level-5 pass
 * reports what lands on a changed line, and only that: the older errors of a
 * touched file are not this change's.
 */
final class ChangedLineTypeCheckTest extends TestCase
{
    private const EDITED = 'packages/semitexa-x/src/Edited.php';
    private const NEW = 'packages/semitexa-x/src/Added.php';

    #[Test]
    public function an_error_on_a_changed_line_fails_and_an_older_one_does_not(): void
    {
        $result = $this->check([
            [self::EDITED, 12, 'argument.type'],
            [self::EDITED, 40, 'booleanAnd.rightAlwaysTrue'],
        ])->run([self::EDITED]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
        self::assertSame([12], array_column($result->diagnostics, 'line'), 'line 40 is older than this change');
        self::assertSame('phpstan_types', $result->diagnostics[0]['check']);
        self::assertStringContainsString('Edited.php:12', $result->rawSignal);
    }

    #[Test]
    public function only_older_errors_pass(): void
    {
        $result = $this->check([[self::EDITED, 40, 'argument.type'], [self::EDITED, 9, 'argument.type']])->run([self::EDITED]);

        self::assertSame(PhpstanRunResult::STATUS_PASS, $result->status, 'hunk +10,3 is lines 10-12');
        self::assertSame([], $result->diagnostics);
    }

    #[Test]
    public function a_file_git_does_not_track_is_changed_on_every_line(): void
    {
        $result = $this->check([[self::NEW, 300, 'return.type']])->run([self::NEW]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
    }

    #[Test]
    public function semitexa_rules_are_left_to_phpstan_di(): void
    {
        $result = $this->check([[self::EDITED, 11, 'semitexa.injectionViaConstructor']])->run([self::EDITED]);

        self::assertSame(PhpstanRunResult::STATUS_PASS, $result->status);
    }

    /** `./a` is `a` to PHPStan: a key spelled otherwise dropped every diagnostic, a silent pass. */
    #[Test]
    public function a_path_spelled_with_dot_segments_is_still_checked(): void
    {
        $result = $this->check([[self::EDITED, 12, 'argument.type']])->run(['./packages/semitexa-x//src/../src/Edited.php']);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $result->status);
    }

    /** Line 11 changed the signature of send(); its caller on line 40 did not change, and broke. */
    #[Test]
    public function a_caller_of_a_changed_declaration_is_reported_on_its_unchanged_line(): void
    {
        $result = $this->check([
            [self::EDITED, 40, 'argument.type', 'Parameter #1 $x of method App\\Edited::send() expects int, string given.'],
            [self::EDITED, 41, 'argument.type', 'Parameter #1 $x of method App\\Other::sendAll() expects int, string given.'],
        ])->run([self::EDITED]);

        self::assertSame([40], array_column($result->diagnostics, 'line'), 'sendAll() was not declared on a changed line');
    }

    /** Line 12 declares $id: an older error on $idempotencyKey is not this change's (review). */
    #[Test]
    public function a_declared_name_matches_only_as_a_whole_name(): void
    {
        $result = $this->check([
            [self::EDITED, 40, 'property.notFound', 'Access to an undefined property App\\Edited::$idempotencyKey.'],
            [self::EDITED, 41, 'property.notFound', 'Access to an undefined property App\\Edited::$id.'],
        ])->run([self::EDITED]);

        self::assertSame([41], array_column($result->diagnostics, 'line'));
    }

    /**
     * ai:verify --git-ref=<ref> on a clean branch: against HEAD a committed
     * change has no changed line, and its errors were dropped (review).
     */
    #[Test]
    public function the_changed_lines_are_measured_against_the_requested_ref(): void
    {
        $errors = [[self::EDITED, 12, 'argument.type']];

        $againstRef = $this->check($errors, committed: true, baseRef: 'origin/develop')->run([self::EDITED]);
        $againstHead = $this->check($errors, committed: true)->run([self::EDITED]);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, $againstRef->status);
        self::assertSame([12], array_column($againstRef->diagnostics, 'line'));
        self::assertSame(PhpstanRunResult::STATUS_PASS, $againstHead->status, 'the working tree is clean against HEAD');
    }

    #[Test]
    public function what_level_0_already_reported_is_not_reported_twice(): void
    {
        $same = ['path' => self::EDITED, 'line' => 12, 'identifier' => 'method.notFound', 'message' => 'Call to an undefined method.'];
        $di = new PhpstanRunResult(PhpstanRunResult::STATUS_FAIL, [$same + ['check' => 'phpstan_di']], 'phpstan_di → 1', 1);
        $types = new PhpstanRunResult(PhpstanRunResult::STATUS_FAIL, [$same + ['check' => 'phpstan_types']], 'phpstan_types → 1', 1);

        $merged = ChangedLineTypeCheck::merge($di, $types);
        self::assertCount(1, $merged->diagnostics);
        self::assertSame('phpstan_di', $merged->diagnostics[0]['check']);

        $alone = ChangedLineTypeCheck::merge(new PhpstanRunResult(PhpstanRunResult::STATUS_PASS, [], 'phpstan_di → 0'), $types);
        self::assertSame(PhpstanRunResult::STATUS_FAIL, $alone->status, 'not a duplicate when phpstan_di did not report it');
        self::assertCount(1, $alone->diagnostics);
    }

    #[Test]
    public function the_merged_result_is_the_worse_of_the_two(): void
    {
        $pass = new PhpstanRunResult(PhpstanRunResult::STATUS_PASS, [], 'phpstan_di → 0 violations');
        $fail = new PhpstanRunResult(PhpstanRunResult::STATUS_FAIL, [['line' => 1]], 'phpstan_types → 1', 1);
        $error = new PhpstanRunResult(PhpstanRunResult::STATUS_ERROR, [], 'phpstan crashed', 255);

        self::assertSame(PhpstanRunResult::STATUS_FAIL, ChangedLineTypeCheck::merge($pass, $fail)->status);
        self::assertSame(PhpstanRunResult::STATUS_FAIL, ChangedLineTypeCheck::merge($fail, $pass)->status);
        self::assertSame(PhpstanRunResult::STATUS_ERROR, ChangedLineTypeCheck::merge($fail, $error)->status, 'a check that could not run is not a pass');
        self::assertSame('phpstan_di → 0 violations; phpstan_types → 1', ChangedLineTypeCheck::merge($pass, $fail)->rawSignal);
    }

    /**
     * Edited.php is tracked, its change one hunk at lines 10-12 against HEAD
     * or, $committed, against origin/develop only; Added.php is not tracked.
     * PHPStan reports $errors.
     *
     * @param list<array{0: string, 1: int, 2: string, 3?: string}> $errors
     */
    private function check(array $errors, bool $committed = false, string $baseRef = 'HEAD'): ChangedLineTypeCheck
    {
        $root = sys_get_temp_dir() . '/changed-line-type-check';
        @mkdir($root . '/packages/semitexa-x/src', 0777, true);
        // Lines 11 and 12 declare send() and $id: changed lines.
        file_put_contents($root . '/' . self::EDITED, str_repeat("\n", 10) . "    public function send(int \$x): void {}\n    private int \$id;\n" . str_repeat("\n", 40));
        $files = [];
        foreach ($errors as $error) {
            [$path, $line, $identifier] = $error;
            $files[$root . '/' . $path]['messages'][] = ['message' => $error[3] ?? 'x', 'line' => $line, 'identifier' => $identifier];
            $files[$root . '/' . $path]['errors'] = count($files[$root . '/' . $path]['messages']);
        }
        $phpstanOutput = (string) json_encode(['totals' => ['errors' => 0, 'file_errors' => count($errors)], 'files' => $files, 'errors' => []]);

        $process = new class ($phpstanOutput, $errors === [] ? 0 : 1, $committed) implements ProcessRunner {
            public function __construct(private readonly string $phpstanOutput, private readonly int $phpstanExit, private readonly bool $committed) {}

            public function run(array $command, string $cwd): array
            {
                if ($command[0] !== 'git') {
                    return ['exit' => $this->phpstanExit, 'output' => $this->phpstanOutput];
                }
                $file = end($command);
                if (in_array('ls-files', $command, true)) {
                    return ['exit' => $file === 'Edited.php' ? 0 : 1, 'output' => ''];
                }

                // Committed: nothing differs from HEAD, the hunk is against origin/develop.
                if ($this->committed && !in_array('origin/develop', $command, true)) {
                    return ['exit' => 0, 'output' => ''];
                }

                return ['exit' => 0, 'output' => "diff --git a/src/Edited.php b/src/Edited.php\n@@ -10,1 +10,3 @@ class Edited\n-old\n+a\n+b\n+c\n"];
            }
        };

        return new ChangedLineTypeCheck($root, $process, new PhpstanRunner(
            projectRoot: $root,
            processRunner: $process,
            phpstanBinary: __FILE__,
            configPath: __FILE__,
            level: ChangedLineTypeCheck::LEVEL,
            check: 'phpstan_types',
        ), $baseRef);
    }
}
