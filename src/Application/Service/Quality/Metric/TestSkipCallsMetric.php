<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality\Metric;

use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\PhpFiles;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * Places a test can decide not to run.
 *
 * A skip is green. FreshInstallReadinessSmokeTest turned a SQL syntax error
 * into "schema unavailable" and skipped, so the suite stayed green over a real
 * failure. Some skips are right — a permission test cannot run as root — which
 * is why this is a ratchet and not a ban: a new one is accepted with a reason.
 */
#[AsQualityMetric(
    id: 'tests.skip-calls',
    sees: 'markTestSkipped( / markTestIncomplete( calls in packages/*/tests and src/modules/*/tests, per package or module',
    blind: 'whether a skip actually fires at runtime, and skips expressed as #[Requires*] attributes or early returns',
)]
final class TestSkipCallsMetric implements QualityMetricInterface
{
    public function measure(string $projectRoot): Measurement
    {
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
                $counts[$key] = ($counts[$key] ?? 0)
                    + (int) preg_match_all('/(?:->|::)mark(?:TestSkipped|TestIncomplete)\s*\(/', (string) file_get_contents($file));
            }
        }

        return new Measurement($counts);
    }
}
