<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\PhpFiles;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;

/**
 * How many test files (named ...Test.php) the project has, where test:run
 * discovers them in the trees tests.skip-calls scans: every package's and
 * module's tests/. A reading for
 * right.test-quality only, not a ledger metric (more tests is not worse),
 * so a tree with no tests scores no test quality rather than full marks for
 * skipping nothing.
 */
final class TestFilesCount implements QualityMetricInterface
{
    public function measure(string $projectRoot): Measurement
    {
        // The same trees tests.skip-calls scans, so the two inputs of one
        // criterion speak of the same tests.
        $dirs = [];
        foreach (PhpFiles::packages($projectRoot) as $name => $dir) {
            $dirs[$name] = $dir . '/tests';
        }
        foreach (glob($projectRoot . '/src/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs['modules/' . basename($dir)] = $dir . '/tests';
        }
        $counts = [];
        foreach ($dirs as $key => $dir) {
            foreach (PhpFiles::under($dir) as $file) {
                if (str_ends_with($file, 'Test.php')) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        return new Measurement($counts);
    }
}
