<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Similarity;

/**
 * Runs per-kind duplication rules against a pre-built {@see SimilarityIndex}.
 *
 * Rule table (kept deliberately small — each rule should correspond to a
 * real failure mode a human reviewer would flag):
 *
 *   handler:
 *     - BLOCK: another handler class in the same module has the identical name
 *     - BLOCK: another handler in the same module already targets the same
 *              `(payload_fqcn, resource_fqcn)` pair (double binding)
 *
 *   payload:
 *     - BLOCK: another payload class with the identical name exists in the
 *              same module (file would be overwritten or double-registered)
 *     - BLOCK: another payload in any module already binds the same
 *              `(route_path, route_method)` pair — route collision
 *     - WARN : close name (Levenshtein ≤ 2) in the same module
 *
 *   listener:
 *     - BLOCK: listener class with identical name in the same module
 *     - WARN : another listener in the same module already subscribes to
 *              the same event FQCN
 */
final class DuplicateDetector
{
    private const NAME_CLOSENESS_THRESHOLD = 2;

    public function __construct(
        private readonly SimilarityIndex $index,
    ) {}

    /**
     * @return list<SimilarityFinding>
     */
    public function check(DuplicateQuery $query): array
    {
        return match ($query->kind) {
            'handler'  => $this->checkHandler($query),
            'payload'  => $this->checkPayload($query),
            'listener' => $this->checkListener($query),
            default    => [],
        };
    }

    /**
     * @return list<SimilarityFinding>
     */
    private function checkHandler(DuplicateQuery $q): array
    {
        $findings = [];
        foreach ($this->index->ofKindInModule('handler', $q->module) as $art) {
            if ($art->className === $q->className) {
                $findings[] = new SimilarityFinding(
                    severity: SimilarityFinding::SEVERITY_BLOCK,
                    rule: 'handler.same_class_in_module',
                    message: "Handler '{$q->className}' already exists in module '{$q->module}'",
                    priorArtPath: $art->relativePath,
                    priorArtFqcn: $art->fqcn,
                    details: ['module' => $q->module, 'className' => $q->className],
                );
                continue;
            }
            $payloadFqcn = $q->extras['payload_fqcn'] ?? '';
            $resourceFqcn = $q->extras['resource_fqcn'] ?? '';
            if ($payloadFqcn === '' || $resourceFqcn === '') {
                continue;
            }
            if (
                ($art->extras['payload_fqcn'] ?? '') === $payloadFqcn
                && ($art->extras['resource_fqcn'] ?? '') === $resourceFqcn
            ) {
                $findings[] = new SimilarityFinding(
                    severity: SimilarityFinding::SEVERITY_BLOCK,
                    rule: 'handler.duplicate_payload_resource_pair',
                    message: "Handler for ({$this->shortName($payloadFqcn)}, {$this->shortName($resourceFqcn)}) already exists",
                    priorArtPath: $art->relativePath,
                    priorArtFqcn: $art->fqcn,
                    details: [
                        'payload_fqcn'  => $payloadFqcn,
                        'resource_fqcn' => $resourceFqcn,
                    ],
                );
            }
        }
        return $findings;
    }

    /**
     * @return list<SimilarityFinding>
     */
    private function checkPayload(DuplicateQuery $q): array
    {
        $findings = [];
        foreach ($this->index->ofKindInModule('payload', $q->module) as $art) {
            if ($art->className === $q->className) {
                $findings[] = new SimilarityFinding(
                    severity: SimilarityFinding::SEVERITY_BLOCK,
                    rule: 'payload.same_class_in_module',
                    message: "Payload '{$q->className}' already exists in module '{$q->module}'",
                    priorArtPath: $art->relativePath,
                    priorArtFqcn: $art->fqcn,
                    details: ['module' => $q->module, 'className' => $q->className],
                );
                continue;
            }
            $distance = levenshtein(strtolower($q->className), strtolower($art->className));
            if ($distance > 0 && $distance <= self::NAME_CLOSENESS_THRESHOLD) {
                $findings[] = new SimilarityFinding(
                    severity: SimilarityFinding::SEVERITY_WARN,
                    rule: 'payload.close_name_in_module',
                    message: "Payload '{$q->className}' is very similar to existing '{$art->className}' (edit distance {$distance})",
                    priorArtPath: $art->relativePath,
                    priorArtFqcn: $art->fqcn,
                    details: [
                        'existing_class' => $art->className,
                        'distance'       => (string) $distance,
                    ],
                );
            }
        }

        $routePath = $q->extras['route_path'] ?? '';
        $routeMethod = $q->extras['route_method'] ?? '';
        if ($routePath !== '' && $routeMethod !== '') {
            foreach ($this->index->ofKind('payload') as $art) {
                if (
                    ($art->extras['route_path'] ?? '') === $routePath
                    && ($art->extras['route_method'] ?? '') === $routeMethod
                ) {
                    $findings[] = new SimilarityFinding(
                        severity: SimilarityFinding::SEVERITY_BLOCK,
                        rule: 'payload.route_already_bound',
                        message: "Route {$routeMethod} {$routePath} is already bound by '{$art->className}'",
                        priorArtPath: $art->relativePath,
                        priorArtFqcn: $art->fqcn,
                        details: [
                            'route_path'   => $routePath,
                            'route_method' => $routeMethod,
                        ],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<SimilarityFinding>
     */
    private function checkListener(DuplicateQuery $q): array
    {
        $findings = [];
        foreach ($this->index->ofKindInModule('listener', $q->module) as $art) {
            if ($art->className === $q->className) {
                $findings[] = new SimilarityFinding(
                    severity: SimilarityFinding::SEVERITY_BLOCK,
                    rule: 'listener.same_class_in_module',
                    message: "Listener '{$q->className}' already exists in module '{$q->module}'",
                    priorArtPath: $art->relativePath,
                    priorArtFqcn: $art->fqcn,
                    details: ['module' => $q->module, 'className' => $q->className],
                );
                continue;
            }
            $eventFqcn = $q->extras['event_fqcn'] ?? '';
            if ($eventFqcn !== '' && ($art->extras['event_fqcn'] ?? '') === $eventFqcn) {
                $findings[] = new SimilarityFinding(
                    severity: SimilarityFinding::SEVERITY_WARN,
                    rule: 'listener.duplicate_event_subscription',
                    message: "Another listener in module '{$q->module}' already subscribes to {$this->shortName($eventFqcn)}",
                    priorArtPath: $art->relativePath,
                    priorArtFqcn: $art->fqcn,
                    details: ['event_fqcn' => $eventFqcn],
                );
            }
        }
        return $findings;
    }

    private function shortName(string $fqcn): string
    {
        return strrpos($fqcn, '\\') !== false ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn;
    }
}
