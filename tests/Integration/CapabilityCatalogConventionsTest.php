<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use ReflectionProperty;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphMechanismsCommand;
use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;
use Semitexa\Dev\Application\Service\Capability\FrameworkCapabilityCatalog;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Conventions the catalog depends on but no single package can enforce.
 *
 * Per-package guards check their own attributes. Nothing there can see that two
 * packages picked the same id, or that a package-level declaration landed on
 * some arbitrary class — and those are exactly the mistakes that only appear
 * once the declarations are collected together.
 */
final class CapabilityCatalogConventionsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function catalog(): array
    {
        $discovery = new ClassDiscovery();
        $discovery->initialize();

        return (new FrameworkCapabilityCatalog($discovery))->all();
    }

    #[Test]
    public function the_catalog_is_not_empty(): void
    {
        // Guards the guard: an empty catalog would make everything below
        // vacuously true, and an empty catalog is itself the failure this whole
        // effort exists to prevent.
        self::assertNotEmpty(self::catalog());
    }

    #[Test]
    public function ids_are_unique_across_every_package(): void
    {
        // A verify finding points at an id. Two packages answering to one id
        // means a finding sends the reader to the wrong mechanism, and neither
        // package's own guard can see the collision.
        $ids = array_map(static fn (array $c): string => (string) $c['id'], self::catalog());
        $duplicates = array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));

        self::assertSame([], $duplicates, 'capability id declared twice: ' . implode(', ', $duplicates));
    }

    #[Test]
    public function a_package_level_capability_lives_on_the_conventional_class(): void
    {
        // "Any class" without a fixed place scatters the declaration: a reviewer
        // would not know where to look to see whether a package describes
        // itself, and a guard would have nothing definite to check.
        $offenders = [];
        foreach (self::catalog() as $capability) {
            if ($capability['kind'] !== 'package') {
                continue;
            }
            if ($capability['declared_by_short'] !== 'Capabilities') {
                $offenders[] = $capability['id'] . ' on ' . $capability['declared_by'];
            }
        }

        self::assertSame([], $offenders, 'package capability off-convention: ' . implode(', ', $offenders));
    }

    #[Test]
    public function kind_is_one_of_the_two_shapes(): void
    {
        foreach (self::catalog() as $capability) {
            self::assertContains($capability['kind'], ['mechanism', 'package'], (string) $capability['id']);
        }
    }

    #[Test]
    public function a_package_capability_is_never_rendered_as_an_attribute(): void
    {
        // The consequence that actually breaks. A package ships nothing to write
        // in code, so printing #[Capabilities] would name something nobody can
        // type — and the reader would go looking for an attribute that does not
        // exist. Asserting on the rendered output rather than on the class name,
        // because the class name is a proxy and the output is the thing that
        // misleads.
        $packageIds = array_map(
            static fn (array $c): string => (string) $c['id'],
            array_values(array_filter(self::catalog(), static fn (array $c): bool => $c['kind'] === 'package')),
        );

        self::assertNotEmpty($packageIds, 'nothing to check: no package-level capability declared');

        // Scoped to the header line, which is where the two shapes actually
        // differ: a mechanism prints `id  #[AsDeferred]`, a package prints
        // `id  semitexa/x`. Scanning the whole block was a wider proxy that
        // held only while no summary mentioned an attribute — and several
        // legitimately do, since "#[ExternalApi] routes with #[ApiVersion]" is
        // the clearest way to say what a package offers. Forbidding that prose
        // would trade an accurate description for a passing assertion.
        foreach ($packageIds as $id) {
            self::assertStringNotContainsString(
                '#[',
                self::headerLine(self::render($id), $id),
                $id . ' rendered as an attribute',
            );
        }
    }

    /** The `  <id>  <package-or-attribute>` line from a rendered capability. */
    private static function headerLine(string $rendered, string $id): string
    {
        foreach (explode("\n", $rendered) as $line) {
            if (str_starts_with($line, '  ' . $id . ' ')) {
                return $line;
            }
        }

        self::fail('no header line for ' . $id . ' in: ' . $rendered);
    }

    /** Render one capability through the real command, in process. */
    private static function render(string $id): string
    {
        $command = new DevGraphMechanismsCommand();

        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $p = new ReflectionProperty(DevGraphMechanismsCommand::class, 'classDiscovery');
        $p->setAccessible(true);
        $p->setValue($command, $discovery);

        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($app->find('dev:graph:mechanisms'));
        $tester->execute(['--id' => $id]);

        return $tester->getDisplay();
    }

    #[Test]
    public function every_advertised_package_name_is_one_composer_can_install(): void
    {
        // `package` is not a label. dev:graph:mechanisms prints it as "provided
        // by X" to an agent deciding whether to require X, so a name that does
        // not resolve is worse than no name: it looks actionable and fails.
        //
        // Derived from the namespace, `Semitexa\PlatformUi\` became
        // `semitexa/platformui` while the package installs as
        // `semitexa/platform-ui` — eight capabilities advertising a package
        // that does not exist.
        $known = self::installablePackageNames();
        self::assertNotEmpty($known, 'no composer.json found on disk; the check would be vacuous');

        $unknown = [];
        foreach (self::catalog() as $capability) {
            $package = (string) $capability['package'];
            if (!in_array($package, $known, true)) {
                $unknown[] = $capability['id'] . ' -> ' . $package;
            }
        }

        self::assertSame([], $unknown, 'capability names a package composer cannot install: ' . implode(', ', $unknown));
    }

    /**
     * Every package name present on disk, monorepo checkout or vendor tree.
     *
     * @return list<string>
     */
    private static function installablePackageNames(): array
    {
        $root = ProjectRoot::get();
        $names = [];

        $manifests = array_merge(
            (array) glob($root . '/packages/*/composer.json'),
            (array) glob($root . '/vendor/*/*/composer.json'),
        );

        foreach ($manifests as $manifest) {
            $decoded = json_decode((string) file_get_contents((string) $manifest), true);
            if (is_array($decoded) && is_string($decoded['name'] ?? null)) {
                $names[] = $decoded['name'];
            }
        }

        return array_values(array_unique($names));
    }

    #[Test]
    public function every_package_capability_reaches_a_project_that_never_installed_it(): void
    {
        // The point of the package-level shape: these come from packages outside
        // the default distribution, which a consumer would otherwise never hear
        // about. If this drops to zero, the feature has quietly become
        // mechanism-only again.
        $packageLevel = array_values(array_filter(
            self::catalog(),
            static fn (array $c): bool => $c['kind'] === 'package',
        ));

        self::assertNotEmpty($packageLevel, 'no package advertises itself; the invisible packages are invisible again');
    }

    #[Test]
    public function every_composer_package_declares_what_it_offers(): void
    {
        // The convention held only as long as someone remembered it: eleven
        // packages had gone silent, and not one of those silences was a decision
        // anybody could point to afterwards. `ai:verify` gates this too, but only
        // when a manifest or a declaration file is in the diff — a package added
        // on a branch that touches neither would reach master unnoticed.
        $root = ProjectRoot::get();
        if (!CapabilityIndex::isFullView($root)) {
            self::markTestSkipped('not the monorepo; package coverage cannot be judged from a partial checkout');
        }

        $missing = array_column(CapabilityIndex::packagesWithoutDeclaration($root), 'name');

        self::assertSame([], $missing, 'package ships no ' . CapabilityIndex::DECLARATION_PATH . ': ' . implode(', ', $missing));
    }

    #[Test]
    public function the_shipped_index_sees_packages_this_checkout_never_installed(): void
    {
        // The index exists to advertise what a project has NOT installed, and it
        // was built from the classmap — so the packages no working copy requires
        // (`theme-sky`, `showcase-kit`, `demo`) were the exact ones it dropped,
        // while its `packages` field still counted all forty. An artifact that
        // looks complete and is not is worse than one that admits the gap.
        $root = ProjectRoot::get();
        if (!CapabilityIndex::isFullView($root)) {
            self::markTestSkipped('not the monorepo; there is no packages/ tree to sweep');
        }

        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $catalog = new FrameworkCapabilityCatalog($discovery);

        $installed = array_map(static fn (array $c): string => (string) $c['id'], $catalog->all());
        $onDisk = $catalog->everythingOnDisk($root);
        $onDiskIds = array_map(static fn (array $c): string => (string) $c['id'], $onDisk);

        // A superset, never a replacement: what is loaded stays authoritative.
        self::assertSame([], array_values(array_diff($installed, $onDiskIds)), 'the sweep dropped an installed capability');

        $advertised = array_unique(array_map(static fn (array $c): string => (string) $c['package'], $onDisk));
        $declaring = [];
        foreach ((array) glob($root . '/packages/semitexa-*/' . CapabilityIndex::DECLARATION_PATH) as $file) {
            $manifest = dirname((string) $file, 2) . '/composer.json';
            $decoded = json_decode((string) file_get_contents($manifest), true);
            if (is_array($decoded) && is_string($decoded['name'] ?? null)) {
                $declaring[] = $decoded['name'];
            }
        }

        self::assertNotEmpty($declaring, 'no declarations on disk; the check would be vacuous');
        self::assertSame(
            [],
            array_values(array_diff($declaring, $advertised)),
            'a package declares something the index would not carry',
        );
    }

    #[Test]
    public function ids_stay_unique_once_uninstalled_packages_join_the_set(): void
    {
        // The uniqueness check above reads the live catalog, which is what this
        // checkout installed. The shipped index is built from a wider set, and
        // the widening is what makes a collision invisible: the merge keys by
        // id and keeps the first, so a second package claiming `theme.sky`
        // would be dropped from the artifact in silence rather than reported.
        //
        // Duplicates are counted from the flat list of declarations, not from
        // the merged result — asking the merged result whether the merge lost
        // anything is asking the wrong witness.
        $root = ProjectRoot::get();
        if (!CapabilityIndex::isFullView($root)) {
            self::markTestSkipped('not the monorepo; the on-disk set is not visible');
        }

        $ids = [];
        foreach ((array) glob($root . '/packages/semitexa-*/' . CapabilityIndex::DECLARATION_PATH) as $file) {
            if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', (string) file_get_contents((string) $file), $m) !== 1) {
                continue;
            }

            // Required here rather than left to the autoloader, which by
            // definition cannot resolve the uninstalled packages this test is
            // about. Relying on class_exists() alone would make the result
            // depend on whether some earlier test happened to load the file —
            // and it would read as passing while checking nothing.
            $class = $m[1] . '\\Capabilities';
            if (!class_exists($class, false)) {
                require_once (string) $file;
            }
            if (!class_exists($class, false)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getAttributes(\Semitexa\Core\Attribute\Capability::class) as $attribute) {
                $ids[] = $attribute->newInstance()->id;
            }
        }

        self::assertNotEmpty($ids, 'no declarations read; the check would be vacuous');

        $duplicates = array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));

        self::assertSame([], $duplicates, 'two packages claim one capability id: ' . implode(', ', $duplicates));
    }
}
