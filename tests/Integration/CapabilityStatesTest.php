<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphMechanismsCommand;
use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;
use Semitexa\Dev\Application\Service\Capability\FrameworkCapabilityCatalog;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Showing a project what it has NOT installed.
 *
 * The live catalog can only report what is in the vendor directory, which makes
 * it silent about exactly the case worth reporting: a project without
 * `semitexa/platform-ui` has no way to learn a UI kit exists. Merging the
 * shipped index closes that, and these tests hold the two halves that make it
 * safe — installed wins over the snapshot, and the wording never reads as an
 * instruction to install.
 */
final class CapabilityStatesTest extends TestCase
{
    private static function command(): DevGraphMechanismsCommand
    {
        $command = new DevGraphMechanismsCommand();

        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $p = new ReflectionProperty(DevGraphMechanismsCommand::class, 'classDiscovery');
        $p->setAccessible(true);
        $p->setValue($command, $discovery);

        return $command;
    }

    /** @return list<array<string, mixed>> */
    private static function discover(): array
    {
        $m = new ReflectionMethod(DevGraphMechanismsCommand::class, 'discover');
        $m->setAccessible(true);

        /** @var list<array<string, mixed>> $result */
        $result = $m->invoke(self::command());

        return $result;
    }

    private static function render(string $id): string
    {
        $app = new Application();
        $app->add(self::command());
        $tester = new CommandTester($app->find('dev:graph:mechanisms'));
        $tester->execute(['--id' => $id]);

        return $tester->getDisplay();
    }

    #[Test]
    public function every_entry_carries_a_state(): void
    {
        $capabilities = self::discover();

        self::assertNotEmpty($capabilities);
        foreach ($capabilities as $capability) {
            self::assertContains($capability['state'], ['installed', 'available'], (string) $capability['id']);
        }
    }

    #[Test]
    public function an_indexed_capability_absent_locally_is_reported_as_available(): void
    {
        // The branch that matters, and the one the monorepo can never reach on
        // its own: every package is on disk here, so an earlier version of this
        // test passed while never executing the code it claimed to cover.
        // Feeding merge() directly is the whole fix.
        $merged = CapabilityIndex::merge(
            [['id' => 'ssr.deferred', 'package' => 'semitexa/ssr']],
            [
                ['id' => 'ssr.deferred', 'package' => 'semitexa/ssr'],
                ['id' => 'ui.behavior', 'package' => 'semitexa/platform-ui'],
            ],
        );

        $states = array_column($merged, 'state', 'id');

        self::assertSame('installed', $states['ssr.deferred']);
        self::assertSame('available', $states['ui.behavior'], 'an uninstalled package must surface');
    }

    #[Test]
    public function the_live_declaration_wins_over_a_stale_snapshot(): void
    {
        // The index can be older than the code. Anything installed is described
        // from what is actually in vendor.
        $merged = CapabilityIndex::merge(
            [['id' => 'ssr.deferred', 'summary' => 'current']],
            [['id' => 'ssr.deferred', 'summary' => 'stale']],
        );

        self::assertCount(1, $merged);
        self::assertSame('current', $merged[0]['summary']);
        self::assertSame('installed', $merged[0]['state']);
    }

    #[Test]
    public function nothing_from_the_index_is_dropped(): void
    {
        // If the merge silently lost entries the feature would degrade back to
        // "everything I already have" and still look like it worked.
        $merged = CapabilityIndex::merge([], [
            ['id' => 'a.one'],
            ['id' => 'b.two'],
            ['id' => 'c.three'],
        ]);

        self::assertSame(['a.one', 'b.two', 'c.three'], array_column($merged, 'id'));
        self::assertSame(['available', 'available', 'available'], array_column($merged, 'state'));
    }

    #[Test]
    public function an_installed_capability_is_not_shadowed_by_the_snapshot(): void
    {
        // The index may be older than the code. Anything present locally is
        // reported from the live declaration, so a stale description cannot
        // override the truth sitting in vendor.
        $installed = array_values(array_filter(
            self::discover(),
            static fn (array $c): bool => $c['state'] === 'installed',
        ));

        self::assertNotEmpty($installed);
        foreach ($installed as $capability) {
            self::assertArrayHasKey('declared_by', $capability);
            self::assertTrue(
                class_exists((string) $capability['declared_by']),
                'reported as installed but its declaring class is absent: ' . $capability['id'],
            );
        }
    }

    #[Test]
    public function nothing_loadable_here_is_reported_as_missing(): void
    {
        // Originally: "the monorepo has every package on disk, so nothing should
        // read as available". That premise died the day the index started being
        // built from the packages directory rather than the classmap — the
        // monorepo has `theme-sky`, `showcase-kit` and `demo` on disk and
        // requires none of them, so the index now carries three entries this
        // checkout genuinely cannot use. Reporting those as `available` is the
        // feature working; the old assertion was reading a true statement as a
        // defect.
        //
        // The invariant that actually holds, and the one worth guarding: the
        // merge must never mark something absent that is loaded right here. On
        // disk is not installed, and `available` is a statement about this
        // project's autoloader.
        // Asked of the live catalog, not of `class_exists`. The index builder
        // reads uninstalled declarations by requiring their files, so once
        // anything in the process has done that, `class_exists` answers yes for
        // a class the autoloader never resolved — and this test fails or passes
        // depending on which test ran before it. The catalog is the definition
        // of installed rather than a proxy for it.
        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $installed = array_column((new FrameworkCapabilityCatalog($discovery))->all(), 'id');
        self::assertNotEmpty($installed, 'no installed capabilities; the check would be vacuous');

        $wronglyAbsent = [];
        foreach (self::discover() as $capability) {
            if ($capability['state'] === 'available' && in_array((string) $capability['id'], $installed, true)) {
                $wronglyAbsent[] = (string) $capability['id'];
            }
        }

        self::assertSame([], $wronglyAbsent, 'reported as not installed while the live catalog carries it');
    }

    #[Test]
    public function the_wording_reports_a_fact_and_does_not_order_an_install(): void
    {
        // The hard constraint of this feature. Adding a dependency to someone's
        // application is their decision, and an agent reading this must report
        // and stop. A line carrying a ready-to-run install command is one an
        // agent will run.
        //
        // Rendered from an AVAILABLE entry: the first version of this test
        // rendered an installed one, so the not-installed line never appeared
        // and the assertion held no matter what that line said.
        $rendered = self::renderAvailable();

        self::assertStringContainsString('Not installed', $rendered, 'the available branch did not render');
        self::assertStringNotContainsString('composer require', $rendered);
        self::assertStringNotContainsString('composer install', $rendered);
        self::assertStringContainsString("operator's call", $rendered, 'the decision stays with the operator');
    }

    /**
     * Render one synthetic available capability through the real output path.
     */
    private static function renderAvailable(): string
    {
        $capability = [
            'id' => 'demo.available',
            'summary' => 'A capability this project does not have.',
            'use_when' => 'when demonstrating the available state',
            'avoid_when' => 'never, it is a fixture',
            'replaces' => [],
            'see_also' => '',
            'kind' => 'package',
            'declared_by' => 'Demo\\Capabilities',
            'declared_by_short' => 'Capabilities',
            'package' => 'semitexa/demo-not-installed',
            'state' => 'available',
        ];

        $command = self::command();
        $m = new ReflectionMethod(DevGraphMechanismsCommand::class, 'renderCapability');
        $m->setAccessible(true);

        $output = new BufferedOutput();
        $m->invoke($command, $output, $capability);

        return $output->fetch();
    }
}
