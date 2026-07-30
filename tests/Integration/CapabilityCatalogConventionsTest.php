<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use ReflectionProperty;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphMechanismsCommand;
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
}
