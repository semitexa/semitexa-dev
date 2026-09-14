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
     * Labelled by the loop the detector walks, rotated to its smallest name, so
     * the same cycle found from two entry points is one entry.
     *
     * So none of them is a legitimate exception; they are six composer
     * requirements nothing backs. Removing them is tracked as
     * tk-unbacked-package-requires and is a deliberate change, because dropping
     * a require changes what a consumer gets transitively.
     *
     * @var list<string>
     */
    private const KNOWN_CYCLES = [
        'semitexa/cms -> semitexa/os',
        'semitexa/core -> semitexa/docs',
        'semitexa/core -> semitexa/tenancy',
        'semitexa/ssr -> semitexa/theme',
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
     * Every cycle, not only mutual pairs.
     *
     * `A -> B -> C -> A` closes a loop with no reciprocal edge anywhere in it,
     * and a two-node check never sees it — which is precisely the outward
     * dependency the policy forbids. Depth-first over the whole graph instead.
     *
     * @return list<string> one normalised label per cycle found
     */
    private function cycles(): array
    {
        $graph = $this->graph();
        $state = [];
        $found = [];

        $walk = function (string $node, array $stack) use (&$walk, &$state, &$found, $graph): void {
            $state[$node] = 'open';
            $stack[] = $node;

            foreach ($graph[$node] ?? [] as $next) {
                if (!isset($graph[$next])) {
                    continue;
                }
                if (($state[$next] ?? '') === 'open') {
                    $loop = array_slice($stack, array_search($next, $stack, true));
                    // Normalised so the same loop reported from two entry points
                    // is one entry: rotate to the smallest name, then join.
                    $smallest = array_search(min($loop), $loop, true);
                    $rotated = array_merge(array_slice($loop, $smallest), array_slice($loop, 0, $smallest));
                    $found[implode(' -> ', $rotated)] = true;
                    continue;
                }
                if (!isset($state[$next])) {
                    $walk($next, $stack);
                }
            }

            $state[$node] = 'closed';
        };

        foreach (array_keys($graph) as $node) {
            if (!isset($state[$node])) {
                $walk($node, []);
            }
        }

        $cycles = array_keys($found);
        sort($cycles);

        return $cycles;
    }

    /**
     * No package may depend, directly or through others, on one that depends
     * back — except the cycles on record, and that list may only shrink.
     */
    #[Test]
    public function no_new_cycle_appears(): void
    {
        $new = array_values(array_diff($this->cycles(), self::KNOWN_CYCLES));

        self::assertSame([], $new, implode("\n", [
            'A package depends on one that depends back, which no shape in the policy allows.',
            'Either the dependency belongs behind a contract the depended-upon package declares,',
            'or the require is unbacked by code and should not exist.',
        ]));
    }

    /** And the recorded list may only shrink — a fixed one rots into a permission. */
    #[Test]
    public function the_recorded_cycles_have_not_been_resurrected(): void
    {
        $gone = array_values(array_diff(self::KNOWN_CYCLES, $this->cycles()));

        self::assertSame([], $gone, implode("\n", [
            'These cycles are gone — delete them from KNOWN_CYCLES so the ratchet tightens:',
            implode(', ', $gone),
        ]));
    }

    /**
     * DIRECTION, not only cycles.
     *
     * A one-way `update -> feature` edge breaks rule 1 and closes no loop, so
     * the cycle check above would pass it. The two packages the policy names by
     * position are pinned here: core is the foundation and requires nothing in
     * the workspace, update is lifecycle and may reach the foundation and
     * persistence but never outward to a feature.
     *
     * Only those two, deliberately. Classifying all 42 packages into layers
     * would be inventing a map rather than recording the decisions that exist.
     */
    #[Test]
    public function the_named_packages_depend_only_inward(): void
    {
        $graph = $this->graph();

        $inwardOnly = [
            // Foundation. The two it does require are unbacked by code and are
            // the KNOWN_CYCLES entries above — see tk-unbacked-package-requires.
            'semitexa/core' => ['semitexa/docs', 'semitexa/tenancy'],
            // Lifecycle: it owns #[AsDataPatch] and os, tasks, platform-settings
            // and prompt require IT. Foundation and persistence only.
            'semitexa/update' => ['semitexa/core', 'semitexa/orm'],
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
