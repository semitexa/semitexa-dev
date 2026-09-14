<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cross-package dependency DIRECTION, which nothing declared before.
 *
 * The policy is derived from decisions already made rather than invented:
 *
 *  1. LIFECYCLE PACKAGES ARE DEPENDED UPON, THEY DO NOT DEPEND OUTWARD.
 *     semitexa/update owns #[AsDataPatch]; os, tasks and platform-settings
 *     hard-require update to use it, and semitexa/prompt followed the same
 *     shape for #[AsUpdateAdvisory].
 *  2. CORE DECLARES THE CONTRACT, PACKAGES SATISFY IT. Core owns
 *     #[AsDoctorCheck] and DoctorCheckInterface; cache and orm implement them
 *     without core knowing either exists.
 *  3. WHERE THE RULE BITES, THE ANSWER IS A CONTRACT, NOT AN EDGE. RouteExecutor
 *     in core cannot see #[ExternalApi] in semitexa-api — and the fix was
 *     ExceptionResponseMapperInterface in core, satisfied by the package, not a
 *     require pointing outward.
 *
 * The check here is the smallest thing that keeps all three true: a cycle in the
 * require graph means some package is depended upon AND depends outward, which
 * none of the three shapes allows.
 *
 * A RATCHET, not a clean sweep. Four cycles exist today and the list is
 * allowed to shrink, never grow.
 */
final class PackageDependencyDirectionTest extends TestCase
{
    /**
     * Mutual requires that exist today. EVERY ONE IS UNBACKED BY CODE —
     * measured 2026-09-14, zero references of any type (php, twig, yaml, json,
     * js) in either direction:
     *
     *   core    -> docs       0 references
     *   core    -> tenancy    0 references (the one mention is a docblock in
     *                         TenancyBootstrapperInterface, whose whole purpose
     *                         is to AVOID this dependency)
     *   cms    <-> os         0 references either way
     *   ssr    <-> theme      0 references either way
     *
     * Recorded as strongly connected COMPONENTS, sorted and joined, so core's
     * two entanglements read as the one component they really are — and so a
     * component that grows by a member is a change the ratchet notices.
     *
     * So none of them is a legitimate exception; they are six composer
     * requirements nothing backs. Removing them is tracked as
     * tk-unbacked-package-requires and is a deliberate change, because dropping
     * a require changes what a consumer gets transitively.
     *
     * @var list<string>
     */
    private const KNOWN_CYCLES = [
        'semitexa/cms, semitexa/os',
        'semitexa/core, semitexa/docs, semitexa/tenancy',
        'semitexa/ssr, semitexa/theme',
    ];

    /** @return array<string, list<string>> package => required semitexa packages */
    private function graph(): array
    {
        $root = dirname(__DIR__, 4);
        $graph = [];

        foreach (glob($root . '/packages/*/composer.json') ?: [] as $path) {
            $json = json_decode((string) file_get_contents($path), true);
            if (!is_array($json) || !is_string($json['name'] ?? null)) {
                continue;
            }

            $requires = [];
            foreach (array_keys((array) ($json['require'] ?? [])) as $dependency) {
                if (is_string($dependency) && str_starts_with($dependency, 'semitexa/')) {
                    $requires[] = $dependency;
                }
            }

            sort($requires);
            $graph[$json['name']] = $requires;
        }

        return $graph;
    }

    #[Test]
    public function the_package_graph_is_readable_at_all(): void
    {
        $graph = $this->graph();

        self::assertGreaterThan(30, count($graph), 'this must run in the monorepo, or it proves nothing');
    }

    /**
     * No package may require one that requires it back — except the four on
     * record, and that list may only shrink.
     */
    /**
     * Strongly connected components of two packages or more.
     *
     * COMPONENTS, not individual loops. A depth-first search that records only
     * back edges to a still-open node misses a cycle that closes through a node
     * it already finished: with `A <-> B` allowed, adding `A -> C` and `C -> B`
     * creates `A -> C -> B -> A`, and the search would have visited and closed
     * B before ever seeing C -> B. Tarjan sees it, because that edge does not
     * make a new loop so much as GROW the existing component — and a component
     * that grew is exactly what the ratchet should refuse.
     *
     * It also collapses `core <-> docs` and `core <-> tenancy` into the one
     * component they really are, rather than two pairs that happen to share a
     * member.
     *
     * @return list<string> one sorted, comma-joined component per entry
     */
    private function components(): array
    {
        $graph = $this->graph();
        $index = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $counter = 0;
        $components = [];

        $connect = function (string $node) use (&$connect, &$index, &$low, &$onStack, &$stack, &$counter, &$components, $graph): void {
            $index[$node] = $low[$node] = $counter++;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach ($graph[$node] ?? [] as $next) {
                if (!isset($graph[$next])) {
                    continue;
                }
                if (!isset($index[$next])) {
                    $connect($next);
                    $low[$node] = min($low[$node], $low[$next]);
                } elseif ($onStack[$next] ?? false) {
                    $low[$node] = min($low[$node], $index[$next]);
                }
            }

            if ($low[$node] !== $index[$node]) {
                return;
            }

            $component = [];
            do {
                $member = array_pop($stack);
                $onStack[$member] = false;
                $component[] = $member;
            } while ($member !== $node);

            if (count($component) > 1) {
                sort($component);
                $components[] = implode(', ', $component);
            }
        };

        foreach (array_keys($graph) as $node) {
            if (!isset($index[$node])) {
                $connect($node);
            }
        }

        sort($components);

        return $components;
    }

    /**
     * No package may depend, directly or through others, on one that depends
     * back — except the components on record, and that list may only shrink.
     */
    #[Test]
    public function no_new_cycle_appears(): void
    {
        $new = array_values(array_diff($this->components(), self::KNOWN_CYCLES));

        self::assertSame([], $new, implode("\n", [
            'A package depends on one that depends back, which no shape in the policy allows.',
            'Either the dependency belongs behind a contract the depended-upon package declares,',
            'or the require is unbacked by code and should not exist.',
            'A component that merely GREW counts: it means a new package joined an existing loop.',
        ]));
    }

    /** And the recorded list may only shrink — a fixed one rots into a permission. */
    #[Test]
    public function the_recorded_cycles_have_not_been_resurrected(): void
    {
        $gone = array_values(array_diff(self::KNOWN_CYCLES, $this->components()));

        self::assertSame([], $gone, implode("\n", [
            'These components are gone or changed — update KNOWN_CYCLES so the ratchet tightens:',
            implode(' | ', $gone),
        ]));
    }

    /**
     * DIRECTION, not only cycles.
     *
     * A one-way `update -> feature` edge breaks rule 1 and closes no loop, so
     * the component check above would pass it. The packages the policy names by
     * position are pinned here: core is the foundation and requires nothing in
     * the workspace, while update and prompt are lifecycle and may reach the
     * foundation and persistence but never outward to a feature.
     *
     * Only the packages the policy names by position, deliberately. Classifying
     * all 42 into layers would be inventing a map rather than recording the
     * decisions that exist.
     */
    #[Test]
    public function the_named_packages_depend_only_inward(): void
    {
        $graph = $this->graph();

        $inwardOnly = [
            // Foundation. The two it does require are unbacked by code and are
            // the KNOWN_CYCLES entries above — see tk-unbacked-package-requires.
            'semitexa/core' => ['semitexa/docs', 'semitexa/tenancy'],
            // Lifecycle: it owns #[AsDataPatch] and os, tasks and
            // platform-settings require IT. Foundation and persistence only.
            'semitexa/update' => ['semitexa/core', 'semitexa/orm'],
            // Lifecycle too — rule 1 names it explicitly: prompt followed the
            // same shape for #[AsUpdateAdvisory], so it may reach update and no
            // further outward.
            'semitexa/prompt' => ['semitexa/core', 'semitexa/orm', 'semitexa/update'],
        ];

        $violations = [];
        foreach ($inwardOnly as $package => $allowed) {
            self::assertArrayHasKey($package, $graph, "{$package} is missing, so this proves nothing");

            foreach ($graph[$package] as $dependency) {
                if (!in_array($dependency, $allowed, true)) {
                    $violations[] = $package . ' -> ' . $dependency;
                }
            }
        }

        self::assertSame([], $violations, implode("\n", [
            'A package the policy names by position now depends outward.',
            'Rule 1: lifecycle packages are depended upon, they do not depend outward.',
            'Rule 3: where the foundation appears to need a feature, the answer is a contract it declares.',
        ]));
    }
}
