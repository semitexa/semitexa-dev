<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Phpstan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;

/**
 * Every `path:` under ignoreErrors in the ai:verify PHPStan config must point
 * at a file that still exists.
 *
 * The config sets `reportUnmatchedIgnoredErrors: false` — deliberately, so a
 * per-file run does not fail over exemptions irrelevant to the files being
 * analysed. The cost is that PHPStan will never once mention an exemption that
 * has stopped matching anything. A path-scoped exemption whose file was
 * renamed, moved or deleted therefore lives on forever, reading as a live,
 * deliberate suppression while suppressing nothing at all — and the next
 * person to move that file gets no warning that its exemption did not follow.
 *
 * Existence is the only claim checked here. Whether the suppression is still
 * WARRANTED is a judgement no test can make; whether its file is still there
 * is a fact, and it is the half that rots silently.
 */
final class AiVerifyIgnoredErrorPathsTest extends TestCase
{
    /** Where the config sits inside THIS package, in every layout. */
    private const CONFIG_IN_PACKAGE = 'config/phpstan-ai-verify.neon';

    #[Test]
    public function the_runner_and_this_test_name_the_same_config(): void
    {
        // PhpstanRunner::CONFIG_REL_PATH is workspace-relative
        // (`packages/semitexa-dev/config/...`); this test finds the same file
        // from the package root. Tying them together here means a rename of
        // the config breaks one obvious assertion instead of quietly leaving
        // the sweep below pointed at a file that no longer exists.
        self::assertStringEndsWith(
            self::CONFIG_IN_PACKAGE,
            PhpstanRunner::CONFIG_REL_PATH,
            'the runner loads a different config than this test sweeps',
        );
    }

    #[Test]
    public function every_path_scoped_exemption_points_at_a_file_that_exists(): void
    {
        // This file lives at <package>/tests/Unit/Ai/Verify/Phpstan/, so five
        // levels up is the package root in EVERY layout — the standalone
        // package checkout as well as the workspace, where the same package
        // sits at packages/semitexa-dev.
        $packageRoot = dirname(__DIR__, 5);
        $config = $packageRoot . '/' . self::CONFIG_IN_PACKAGE;

        self::assertFileExists($config, 'the ai:verify PHPStan config itself moved — this test is reading nothing');

        // The exemption paths are written against %currentWorkingDirectory%,
        // which is the WORKSPACE root when ai:verify runs PHPStan, and they
        // name sibling packages (packages/semitexa-core/...). A standalone
        // checkout of this package has no siblings to resolve them against,
        // so the claim is unanswerable there rather than false. Named
        // explicitly, because a skip that reads as a pass is the same defect
        // this test exists to catch.
        $workspaceRoot = dirname($packageRoot, 2);
        if (basename(dirname($packageRoot)) !== 'packages' || !is_dir($workspaceRoot . '/packages')) {
            self::markTestSkipped(
                'standalone package checkout: the exemptions name sibling packages that only exist in the workspace, '
                . 'where test:run runs this test on every gate',
            );
        }

        $contents = (string) file_get_contents($config);

        $matched = preg_match_all(
            '/^\s*path:\s*%currentWorkingDirectory%\/(\S+)\s*$/m',
            $contents,
            $matches,
        );

        self::assertGreaterThan(
            0,
            $matched,
            'no path-scoped exemptions were found — the config format changed and this test stopped reading it',
        );

        $missing = [];
        foreach ($matches[1] as $relative) {
            // Trailing `*` marks a directory-scoped exemption; the directory
            // is the thing that has to exist.
            $target = rtrim($relative, '*');
            $target = rtrim($target, '/');

            if (!file_exists($workspaceRoot . '/' . $target)) {
                $missing[] = $relative;
            }
        }

        self::assertSame([], $missing, sprintf(
            "%d ignoreErrors path(s) in %s point at files that no longer exist. "
            . "They suppress nothing and reportUnmatchedIgnoredErrors is off, so PHPStan will never say so. "
            . "Delete the stale entries (or repoint them if the file moved):\n  - %s",
            count($missing),
            PhpstanRunner::CONFIG_REL_PATH,
            implode("\n  - ", $missing),
        ));
    }
}
