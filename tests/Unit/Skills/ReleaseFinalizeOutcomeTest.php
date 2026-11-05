<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What release-finalize.sh does with each way the tagging can end. On 2026-11-05
 * Packagist was still indexing when its wait ran out, after the tags were pushed:
 * finalize said NOT READY, wrote a report saying postponed, never reached the
 * Published install, and left the session open, so the next preflight would have
 * handed back a version already released.
 *
 * The real script runs here, with its collaborators stubbed.
 */
final class ReleaseFinalizeOutcomeTest extends TestCase
{
    private const SCRIPTS = __DIR__ . '/../../../resources/skills/release-readiness/scripts';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/release-finalize-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        copy(self::SCRIPTS . '/release-finalize.sh', $this->dir . '/release-finalize.sh');
        copy(self::SCRIPTS . '/release-post-merge.sh', $this->dir . '/release-post-merge.sh');
        file_put_contents($this->dir . '/bump-packages.php', '<?php exit((int) getenv("TAG_EXIT"));');
        file_put_contents($this->dir . '/common.sh', <<<'SH'
            RELEASE_ROOT=/nowhere
            RELEASE_SUMMARY_FILE=/nowhere/summary
            RELEASE_VERSION=2026.01.01.0000
            RELEASE_CHANNEL=stable
            require_cmd() { :; }
            load_release_session() { :; }
            info() { :; }
            warn() { printf 'warned\n' >>"$SCRIPT_DIR/calls"; }
            report_failure_reason() { printf 'reason'; }
            mark_release_finalized() { printf 'finalized\n' >>"$SCRIPT_DIR/calls"; }
            write_success_report() { printf 'success report\n' >>"$SCRIPT_DIR/calls"; }
            write_failure_report() { printf 'failure report\n' >>"$SCRIPT_DIR/calls"; }
            SH);
        file_put_contents($this->dir . '/release-published-install.sh', <<<'SH'
            #!/usr/bin/env bash
            printf 'published install\n' >>"$(dirname "$0")/calls"
            exit "${PUBLISHED_EXIT:-0}"
            SH);
        chmod($this->dir . '/release-published-install.sh', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return iterable<string, array{int, int, list<string>, string}> */
    public static function outcomes(): iterable
    {
        yield 'tagged and listed' => [0, 0, ['finalized', 'success report', 'published install'], 'RELEASE READINESS: READY'];
        yield 'tagged, Packagist still indexing' => [3, 0, ['finalized', 'success report', 'warned', 'published install'], 'RELEASE READINESS: READY'];
        yield 'stopped before the cut' => [1, 1, ['failure report'], 'RELEASE READINESS: NOT READY'];
    }

    /** @param list<string> $calls */
    #[Test]
    #[DataProvider('outcomes')]
    public function each_way_tagging_ends_is_told_apart(int $tagExit, int $exit, array $calls, string $verdict): void
    {
        [$code, $output] = $this->finalize($tagExit, 0);

        self::assertSame($exit, $code, $output);
        self::assertSame($calls, $this->calls());
        self::assertStringContainsString($verdict, $output);
    }

    #[Test]
    public function an_unlisted_release_still_answers_to_the_published_install(): void
    {
        [$code, $output] = $this->finalize(3, 1);

        self::assertSame(1, $code);
        self::assertStringContainsString('RELEASED, BUT NEW INSTALLS FAIL', $output);
        self::assertContains('finalized', $this->calls(), 'it was released all the same');
    }

    #[Test]
    public function the_recovery_path_closes_the_same_release_and_says_what_it_could_not_confirm(): void
    {
        [$code, $output] = $this->finalize(3, 0, 'release-post-merge.sh');

        self::assertSame(0, $code, $output);
        self::assertSame(['finalized', 'success report'], $this->calls());
        self::assertStringContainsString('RELEASED, Packagist UNVERIFIED', $output);
    }

    #[Test]
    public function the_recovery_path_still_stops_on_a_failure_before_the_cut(): void
    {
        [$code, $output] = $this->finalize(1, 0, 'release-post-merge.sh');

        self::assertSame(1, $code, $output);
        self::assertSame(['failure report'], $this->calls());
        self::assertStringContainsString('RELEASE READINESS: NOT READY', $output);
    }

    #[Test]
    public function the_wait_for_packagist_has_one_deadline_for_the_whole_sweep(): void
    {
        // Per package, 40 packages and a Packagist that is down held a release for hours.
        $source = (string) file_get_contents(self::SCRIPTS . '/bump-packages.php');
        $sweep = substr($source, (int) strpos($source, 'function verifyPackagistForPackages'));
        $sweep = substr($sweep, 0, (int) strpos($sweep, 'function notifyPackagist('));

        self::assertSame(1, substr_count($sweep, '$sweepEndsAt = time()'));
        self::assertStringNotContainsString('$timeoutSeconds = max(15', $sweep);
        self::assertStringContainsString('waitForPackagistVersion($packageName, $expectedVersion, $expectedReference, max(0, $sweepEndsAt - time())', $sweep);
    }

    /** @return array{int, string} */
    private function finalize(int $tagExit, int $publishedExit, string $script = 'release-finalize.sh'): array
    {
        $command = sprintf(
            'TAG_EXIT=%d PUBLISHED_EXIT=%d bash %s 2>&1',
            $tagExit,
            $publishedExit,
            escapeshellarg($this->dir . '/' . $script),
        );
        exec($command, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /** @return list<string> */
    private function calls(): array
    {
        return file($this->dir . '/calls', FILE_IGNORE_NEW_LINES) ?: [];
    }
}
