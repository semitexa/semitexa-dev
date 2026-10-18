<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\E2eSpecResultCheck;
use Semitexa\Dev\Application\Service\Ai\Verify\E2eSpecTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * A changed Playwright spec is planned as an e2e target and judged by the
 * result the host filed for it. Nothing about it may pass by default: no run,
 * no result, or a result for different content is incomplete, with the reason.
 */
final class E2eSpecVerificationTest extends TestCase
{
    private const SPEC = 'packages/semitexa-tasks/tests/E2E/tasks.spec.ts';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/e2e-verify-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages/semitexa-tasks/tests/E2E', 0777, true);
        file_put_contents($this->root . '/' . self::SPEC, "test('x', async () => {});\n");
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function paths(): iterable
    {
        yield 'a package spec' => [self::SPEC, true];
        yield 'a nested module spec' => ['src/modules/UiPlayground/tests/E2E/grid/live.spec.ts', true];
        yield 'a helper beside the specs' => ['packages/semitexa-tasks/tests/E2E/helpers.ts', false];
        yield 'a spec outside tests/E2E' => ['packages/semitexa-tasks/tests/Unit/x.spec.ts', false];
        yield 'the root config' => ['playwright.config.ts', false];
    }

    #[Test]
    #[DataProvider('paths')]
    public function only_a_spec_the_e2e_phase_discovers_is_a_target(string $path, bool $expected): void
    {
        self::assertSame($expected, E2eSpecTargets::isSpec($path));
    }

    #[Test]
    public function a_changed_spec_is_planned_except_at_minimal_scope_or_once_deleted(): void
    {
        $changed = [new ChangedFile(self::SPEC, 'non_php', ChangedFile::STATUS_MODIFIED)];

        $targets = E2eSpecTargets::of($changed, VerificationPlan::SCOPE_STANDARD);
        self::assertCount(1, $targets);
        self::assertSame(VerificationTarget::TYPE_E2E, $targets[0]->type);
        self::assertSame([self::SPEC], $targets[0]->triggeredBy);

        self::assertSame([], E2eSpecTargets::of($changed, VerificationPlan::SCOPE_MINIMAL));
        self::assertSame([], E2eSpecTargets::of([new ChangedFile(self::SPEC, 'non_php', ChangedFile::STATUS_DELETED)], VerificationPlan::SCOPE_STANDARD));
    }

    #[Test]
    public function without_a_host_run_the_spec_is_incomplete_and_says_so(): void
    {
        $result = (new E2eSpecResultCheck($this->root, null))->check($this->target());

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertStringContainsString('not started by bin/semitexa ai:verify', $result->signal);

        foreach (['../escape', '..', '.hidden'] as $run) {
            $malformed = (new E2eSpecResultCheck($this->root, $run))->check($this->target());
            self::assertSame(VerificationResult::STATUS_INCOMPLETE, $malformed->status, $run);
            // The guard's own reason: without the run-id check these fall
            // through to "the host filed no result", still incomplete.
            self::assertStringContainsString('not started by bin/semitexa ai:verify', $malformed->signal, $run);
        }
    }

    #[Test]
    public function a_run_that_filed_nothing_reports_the_hosts_reason(): void
    {
        $dir = $this->runDir('r1');
        file_put_contents($dir . '/host.txt', "the app is not running, and verify never starts it\n");

        $result = (new E2eSpecResultCheck($this->root, 'r1'))->check($this->target());

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertStringContainsString('the app is not running', $result->signal);
    }

    #[Test]
    public function the_filed_exit_code_is_the_verdict_and_the_counts_are_the_signal(): void
    {
        $dir = $this->runDir('r2');
        $hash = $this->key();
        file_put_contents("{$dir}/{$hash}.exit", "0\n");
        file_put_contents("{$dir}/{$hash}.log", "Running 1 test\n\e[1A\e[2K[1/1] [chromium] › tasks.spec.ts\n\e[1A\e[2K  1 passed (4.2s)\n");

        $pass = (new E2eSpecResultCheck($this->root, 'r2'))->check($this->target());
        self::assertSame(VerificationResult::STATUS_PASS, $pass->status);
        self::assertStringEndsWith('against the app as it was running → 1 passed (4.2s)', $pass->signal);

        file_put_contents("{$dir}/{$hash}.exit", "1\n");
        file_put_contents("{$dir}/{$hash}.log", "    Error: deliberate\n  1 failed\n  2 passed (9s)\n");
        $fail = (new E2eSpecResultCheck($this->root, 'r2'))->check($this->target());
        self::assertSame(VerificationResult::STATUS_FAIL, $fail->status);
        self::assertStringEndsWith('→ 1 failed, 2 passed (9s) — Error: deliberate', $fail->signal);
    }

    #[Test]
    public function a_spec_edited_after_it_ran_is_not_judged_by_the_old_result(): void
    {
        $dir = $this->runDir('r3');
        $hash = $this->key();
        file_put_contents("{$dir}/{$hash}.exit", "0\n");
        file_put_contents($this->root . '/' . self::SPEC, "test('edited', async () => {});\n");

        $result = (new E2eSpecResultCheck($this->root, 'r3'))->check($this->target());

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertStringContainsString('no result for its current content', $result->signal);
    }

    #[Test]
    public function a_runner_that_never_reported_is_not_a_failed_spec(): void
    {
        // An image pull, compose or config failure exits non-zero too, but
        // Playwright printed no counts: that says nothing about the spec.
        $dir = $this->runDir('r4');
        file_put_contents("{$dir}/{$this->key()}.exit", "1\n");
        file_put_contents("{$dir}/{$this->key()}.log", "service \"e2e-runner\" has no image\n");

        $result = (new E2eSpecResultCheck($this->root, 'r4'))->check($this->target());

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status);
        self::assertStringContainsString('without a test report', $result->signal);
    }

    #[Test]
    public function two_specs_with_the_same_body_do_not_share_a_result(): void
    {
        $other = 'packages/semitexa-tasks/tests/E2E/copy.spec.ts';
        copy($this->root . '/' . self::SPEC, $this->root . '/' . $other);
        $dir = $this->runDir('r5');
        file_put_contents("{$dir}/{$this->key()}.exit", "0\n");
        file_put_contents("{$dir}/{$this->key()}.log", "1 passed (1s)\n");

        $copy = E2eSpecTargets::of([new ChangedFile($other, 'non_php')], VerificationPlan::SCOPE_STANDARD)[0];
        $result = (new E2eSpecResultCheck($this->root, 'r5'))->check($copy);

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status, 'the copy was never run');
    }

    private function key(): string
    {
        return E2eSpecResultCheck::key(self::SPEC, (string) hash_file('sha256', $this->root . '/' . self::SPEC));
    }

    private function target(): VerificationTarget
    {
        return E2eSpecTargets::of([new ChangedFile(self::SPEC, 'non_php', ChangedFile::STATUS_MODIFIED)], VerificationPlan::SCOPE_STANDARD)[0];
    }

    private function runDir(string $run): string
    {
        $dir = $this->root . '/' . E2eSpecResultCheck::RESULTS_DIR . '/' . $run;
        mkdir($dir, 0777, true);

        return $dir;
    }
}
