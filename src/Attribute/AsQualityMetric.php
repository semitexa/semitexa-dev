<?php

declare(strict_types=1);

namespace Semitexa\Dev\Attribute;

use Attribute;

/**
 * Marks a class as a quality metric: a number about the codebase that may only
 * go down.
 *
 * Discovered and run by `ai:quality`. The class implements
 * {@see \Semitexa\Dev\Application\Service\Quality\QualityMetricInterface} and
 * has a parameterless constructor — like a doctor check, a metric is a probe of
 * the tree, not a container-managed service.
 *
 * `sees` and `blind` are required on purpose. A number that is made a target
 * stops measuring what it was chosen for; saying up front what the metric does
 * NOT see is what lets a reader tell an improvement from gaming it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsQualityMetric
{
    /** Cheap enough for every `ai:verify` (well under a second). */
    public const TIER_VERIFY = 'verify';

    /** Too slow for every edit; measured at release. */
    public const TIER_RELEASE = 'release';

    /**
     * @param string $id    stable identifier, `area.what` (e.g. "tests.skip-calls")
     * @param string $sees  what the number counts, in one sentence
     * @param string $blind what the number cannot see, in one sentence
     * @param string $tier  {@see self::TIER_VERIFY} or {@see self::TIER_RELEASE}
     */
    public function __construct(
        public string $id,
        public string $sees,
        public string $blind,
        public string $tier = self::TIER_VERIFY,
    ) {
    }
}
