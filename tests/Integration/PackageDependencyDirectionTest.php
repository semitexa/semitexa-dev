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
     * So none of them is a legitimate exception; they are six composer
     * requirements nothing backs. Removing them is tracked as
     * tk-unbacked-package-requires and is a deliberate change, because dropping
     * a require changes what a consumer gets transitively.
     *
     * @var list<string>
     */
    private const KNOWN_CYCLES = [
        'semitexa/cms <-> semitexa/os',
        'semitexa/core <-> semitexa/docs',
        'semitexa/core <-> semitexa/tenancy',
        'semitexa/ssr <-> semitexa/theme',
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
    #[Test]
    public function no_new_mutual_dependency_appears(): void
    {
        $graph = $this->graph();
        $found = [];

        foreach ($graph as $package => $requires) {
            foreach ($requires as $dependency) {
                if (!isset($graph[$dependency])) {
                    continue;
                }
                if (in_array($package, $graph[$dependency], true) && $package < $dependency) {
                    $found[] = $package . ' <-> ' . $dependency;
                }
            }
        }

        sort($found);

        $new = array_values(array_diff($found, self::KNOWN_CYCLES));
        self::assertSame([], $new, implode("\n", [
            'A package requires one that requires it back, which no shape in the policy allows.',
            'Either the dependency belongs behind a contract the depended-upon package declares,',
            'or the require is unbacked by code and should not exist.',
        ]));
    }

    /** And the recorded list may only shrink — a fixed one rots into a permission. */
    #[Test]
    public function the_recorded_cycles_have_not_been_resurrected(): void
    {
        $graph = $this->graph();
        $found = [];

        foreach ($graph as $package => $requires) {
            foreach ($requires as $dependency) {
                if (isset($graph[$dependency]) && in_array($package, $graph[$dependency], true) && $package < $dependency) {
                    $found[] = $package . ' <-> ' . $dependency;
                }
            }
        }

        $gone = array_values(array_diff(self::KNOWN_CYCLES, $found));

        self::assertSame([], $gone, implode("\n", [
            'These cycles are gone — delete them from KNOWN_CYCLES so the ratchet tightens:',
            implode(', ', $gone),
        ]));
    }
}
