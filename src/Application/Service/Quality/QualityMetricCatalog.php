<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Attribute\AsQualityMetric;

/**
 * Every #[AsQualityMetric] class the installation declares, keyed by id.
 *
 * A declaration that cannot be honoured — not implementing the interface, or a
 * second class claiming an id — is an error, not a skip: a metric that silently
 * drops out of the ledger reads exactly like a metric that holds.
 */
final class QualityMetricCatalog
{
    /**
     * @return array<string, array{metric: QualityMetricInterface, meta: AsQualityMetric}>
     */
    public static function discover(ClassDiscovery $discovery): array
    {
        $out = [];
        foreach ($discovery->findClassesWithAttribute(AsQualityMetric::class) as $class) {
            $reflection = new \ReflectionClass($class);
            $meta = ($reflection->getAttributes(AsQualityMetric::class)[0] ?? null)?->newInstance();
            if (!$meta instanceof AsQualityMetric) {
                continue;
            }
            if (!$reflection->implementsInterface(QualityMetricInterface::class)) {
                throw new \LogicException("{$class} declares #[AsQualityMetric] but does not implement " . QualityMetricInterface::class);
            }
            if (isset($out[$meta->id])) {
                throw new \LogicException("quality metric id '{$meta->id}' is declared twice: " . $out[$meta->id]['metric']::class . " and {$class}");
            }
            /** @var QualityMetricInterface $metric */
            $metric = $reflection->newInstance();
            $out[$meta->id] = ['metric' => $metric, 'meta' => $meta];
        }
        ksort($out);

        return $out;
    }
}
