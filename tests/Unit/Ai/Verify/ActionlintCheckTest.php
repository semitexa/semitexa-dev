<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ActionlintTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\CoverageGap;
use Semitexa\Dev\Application\Service\Ai\Verify\ShellProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Symfony\Component\Console\Application;

/**
 * The executor's verdict on a workflow, with a script standing in for
 * actionlint: the real one is a download, and no test needs the network.
 */
final class ActionlintCheckTest extends TestCase
{
    private const WORKFLOW = 'packages/semitexa-installer/.github/workflows/docker-publish.yml';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-actionlint-' . bin2hex(random_bytes(6));
        mkdir(dirname($this->root . '/' . self::WORKFLOW), 0755, true);
        file_put_contents($this->root . '/' . self::WORKFLOW, "on: push\njobs: {}\n");
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function exit_zero_is_a_pass_and_the_workflow_counts_as_read(): void
    {
        // The stub records how it was called: the file by its full path, from its repository.
        $stub = $this->stub('printf "%s\n" "$PWD" "$@" > ' . escapeshellarg($this->root . '/called') . "\nexit 0");

        [$result, $gap] = $this->run_($stub);

        self::assertSame(VerificationResult::STATUS_PASS, $result->status);
        self::assertSame('actionlint 1.7.7 ' . self::WORKFLOW . ' → no problem found', $result->signal);
        self::assertSame(
            [$this->root . '/packages/semitexa-installer', '-no-color', '-oneline', $this->root . '/' . self::WORKFLOW],
            file($this->root . '/called', FILE_IGNORE_NEW_LINES),
        );
        self::assertSame([], $gap->toArray()['unchecked_files']);
        self::assertSame('pass', $gap->adjust('pass'));
    }

    #[Test]
    public function problems_are_a_fail_that_shows_what_actionlint_said(): void
    {
        $said = [
            '.github/workflows/docker-publish.yml:7:5: unexpected key "step" for "job" section. expected one of "steps", "uses" [syntax-check]',
            '.github/workflows/docker-publish.yml:12:9: property "tagz" is not defined in object type {} [expression]',
        ];
        $stub = $this->stub('printf "%s\n" ' . implode(' ', array_map('escapeshellarg', $said)) . "\nexit 1");

        [$result, $gap] = $this->run_($stub);

        self::assertSame(VerificationResult::STATUS_FAIL, $result->status);
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString(self::WORKFLOW . ' → 2 problems: .github/workflows/docker-publish.yml:7:5: unexpected key "step"', $result->signal);
        self::assertSame(
            [
                ['rule' => 'actionlint.syntax-check', 'file' => self::WORKFLOW, 'line' => 7, 'column' => 5, 'message' => 'unexpected key "step" for "job" section. expected one of "steps", "uses"'],
                ['rule' => 'actionlint.expression', 'file' => self::WORKFLOW, 'line' => 12, 'column' => 9, 'message' => 'property "tagz" is not defined in object type {}'],
            ],
            $result->diagnostics,
        );
        // Read and refused is still read.
        self::assertSame([], $gap->toArray()['unchecked_files']);
    }

    #[Test]
    public function no_tool_is_not_a_pass_and_the_workflow_stays_unread(): void
    {
        $missing = $this->root . '/no-such-actionlint';

        [$result, $gap] = $this->run_($missing);

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertTrue($result->required);
        self::assertSame(
            'actionlint: not checked — ' . self::WORKFLOW . " was NOT read, actionlint could not be obtained: the actionlint named, {$missing}, is not an executable file",
            $result->signal,
        );
        self::assertSame([['path' => self::WORKFLOW, 'kind' => ChangedFile::KIND_NON_PHP]], $gap->toArray()['unchecked_files']);
        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $gap->adjust('pass'));
    }

    #[Test]
    public function an_exit_that_is_no_verdict_is_not_a_pass_either(): void
    {
        // actionlint exits 3 on a fatal error, and 1 with nothing said is no list of problems.
        foreach (['echo "fatal: could not read the file"; exit 3' => 'exit 3): fatal: could not read the file', 'exit 1' => 'exit 1): subprocess exited with status 1'] as $body => $expected) {
            [$result] = $this->run_($this->stub($body));

            self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status, $body);
            self::assertStringContainsString('was NOT read, actionlint gave no verdict (' . $expected, $result->signal);
        }
    }

    /**
     * Plans the changed workflow and runs the plan, as ai:verify does.
     *
     * @return array{VerificationResult, CoverageGap}
     */
    private function run_(string $actionlintBinary): array
    {
        $files = [new ChangedFile(self::WORKFLOW, ChangedFile::KIND_NON_PHP)];
        $plan = new VerificationPlan(VerificationPlan::SCOPE_STANDARD, VerificationPlan::SCOPE_STANDARD, $files, ActionlintTargets::of($files));
        $results = (new VerificationExecutor(new Application(), $this->root, new ShellProcessRunner(), actionlintBinary: $actionlintBinary))->execute($plan);
        self::assertCount(1, $results);

        return [$results[0], CoverageGap::of($plan, $results, $this->root)];
    }

    private function stub(string $body): string
    {
        $path = $this->root . '/actionlint-stub-' . bin2hex(random_bytes(4));
        file_put_contents($path, "#!/bin/sh\n{$body}\n");
        chmod($path, 0755);

        return $path;
    }
}
