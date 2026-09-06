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
    #[Test]
    public function every_path_scoped_exemption_points_at_a_file_that_exists(): void
    {
        $root = dirname(__DIR__, 7);
        // Read the path from the runner that actually loads it, so a moved
        // config moves this test with it instead of quietly checking nothing.
        $config = $root . '/' . PhpstanRunner::CONFIG_REL_PATH;

        self::assertFileExists($config, 'the ai:verify PHPStan config itself moved — this test is reading nothing');

        $contents = (string) file_get_contents($config);

        // `path:` entries are written relative to %currentWorkingDirectory%,
        // which is the project root when ai:verify runs PHPStan.
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

            if (!file_exists($root . '/' . $target)) {
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
