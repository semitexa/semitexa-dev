<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality\Metric;

use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\PhpFiles;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * Packages that ship PHP source and no tests.
 *
 * Moved here from PackageTestCoverageRatchetTest, whose list was a PHP const
 * edited by hand. The concrete harm is unchanged: `test:run
 * packages/semitexa-<name>` exits 2 on a package with no tests/, and a package
 * added tomorrow joins them silently. Packages with no PHP source at all (the
 * browser extension, the installer scaffold) are not counted.
 */
#[AsQualityMetric(
    id: 'packages.without-tests',
    sees: 'packages under packages/ that have PHP in src/ and no *Test.php under tests/',
    blind: 'how well a package is tested: one trivial test takes a package off the list',
)]
final class PackagesWithoutTestsMetric implements QualityMetricInterface
{
    public function measure(string $projectRoot): Measurement
    {
        $untested = [];
        foreach (PhpFiles::packages($projectRoot) as $name => $dir) {
            if (PhpFiles::under($dir . '/src') === []) {
                continue;
            }
            $tests = array_filter(PhpFiles::under($dir . '/tests'), static fn (string $f): bool => str_ends_with($f, 'Test.php'));
            if ($tests === []) {
                $untested[$name] = 1;
            }
        }

        return new Measurement($untested);
    }
}
