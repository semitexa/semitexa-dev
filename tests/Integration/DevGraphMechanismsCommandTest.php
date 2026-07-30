<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphMechanismsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The catalog an agent reads to find out what the framework can do.
 *
 * The property under test is that it is DERIVED rather than stored: a consumer
 * project must see a capability added to the framework later without editing
 * anything of its own. So these assertions go through real discovery over the
 * composer classmap, not a fixture — a fixture would pass while the derivation
 * was broken, which is the one failure that matters here.
 */
final class DevGraphMechanismsCommandTest extends TestCase
{
    private static function tester(): CommandTester
    {
        $command = new DevGraphMechanismsCommand();

        // The command takes its collaborator by property injection, which the
        // container does at boot; construct it directly for the test.
        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $p = new ReflectionProperty(DevGraphMechanismsCommand::class, 'classDiscovery');
        $p->setAccessible(true);
        $p->setValue($command, $discovery);

        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('dev:graph:mechanisms'));
    }

    /** @return array<string, mixed> */
    private static function json(CommandTester $t): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($t->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    #[Test]
    public function it_finds_capabilities_declared_by_other_packages(): void
    {
        // semitexa-dev declares none of these itself. If this passes, the
        // derivation genuinely crosses package boundaries, which is what makes
        // `composer update` enough for a consumer project.
        $t = self::tester();
        $t->execute(['--json' => true]);
        $data = self::json($t);

        self::assertGreaterThan(0, $data['count']);
        $packages = array_unique(array_column((array) $data['mechanisms'], 'package'));
        self::assertNotContains('semitexa/dev', $packages, 'the catalog must not be limited to its own package');
        self::assertGreaterThanOrEqual(2, count($packages), 'capabilities should come from more than one package');
    }

    #[Test]
    public function every_entry_carries_both_halves_of_the_advice(): void
    {
        // `useWhen` alone gets a capability applied everywhere. The catalog is
        // only safe to follow if it also says when not to.
        $t = self::tester();
        $t->execute(['--json' => true]);

        foreach ((array) self::json($t)['mechanisms'] as $m) {
            self::assertNotSame('', trim((string) $m['use_when']), $m['id'] . ' has no use_when');
            self::assertNotSame('', trim((string) $m['avoid_when']), $m['id'] . ' has no avoid_when');
            self::assertNotSame('', trim((string) $m['summary']), $m['id'] . ' has no summary');
        }
    }

    #[Test]
    public function area_filter_narrows_to_that_area_only(): void
    {
        $t = self::tester();
        $t->execute(['--json' => true, '--area' => 'ssr']);
        $data = self::json($t);

        self::assertGreaterThan(0, $data['count'], 'the ssr area must not be empty');
        foreach ((array) $data['mechanisms'] as $m) {
            self::assertStringStartsWith('ssr.', (string) $m['id']);
        }
    }

    #[Test]
    public function a_known_id_returns_exactly_one_entry(): void
    {
        $t = self::tester();
        $t->execute(['--json' => true, '--id' => 'ssr.deferred']);
        $data = self::json($t);

        self::assertSame(1, $data['count']);
        self::assertSame('ssr.deferred', ((array) $data['mechanisms'])[0]['id']);
    }

    #[Test]
    public function an_unknown_id_fails_instead_of_returning_an_empty_list(): void
    {
        // An empty success would read as "this capability exists and says
        // nothing", which is worse than a plain miss — the caller would stop
        // looking.
        $t = self::tester();
        $exit = $t->execute(['--id' => 'ssr.does-not-exist']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ssr.does-not-exist', $t->getDisplay());
    }

    #[Test]
    public function an_unknown_id_says_the_index_was_searched_too(): void
    {
        // The wording is the whole point. "Not among the installed packages"
        // leaves open that the capability exists somewhere out of view, and an
        // agent reading that goes and builds the thing by hand — the outcome
        // this catalog exists to prevent. The miss has to read as final.
        $t = self::tester();
        $t->execute(['--id' => 'nothing.here']);

        self::assertStringContainsString('index', $t->getDisplay());
        self::assertStringContainsString('neither', $t->getDisplay());
    }

    #[Test]
    public function an_id_hidden_by_a_state_filter_is_not_reported_as_missing(): void
    {
        // "Not found" would be false: the capability is right there, excluded
        // by the caller's own filter. Naming the state it has is the answer
        // that lets them fix their own command.
        $t = self::tester();
        $exit = $t->execute(['--id' => 'ssr.deferred', '--state' => 'available']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('exists but was filtered out', $t->getDisplay());
        self::assertStringContainsString('installed', $t->getDisplay());
    }

    #[Test]
    public function replaces_entries_survive_into_the_output(): void
    {
        // These are the hook the verify rules will key on, so their presence in
        // the emitted envelope is part of the contract, not incidental.
        $t = self::tester();
        $t->execute(['--json' => true, '--id' => 'ssr.deferred']);
        $entry = ((array) self::json($t)['mechanisms'])[0];

        self::assertNotEmpty($entry['replaces']);
    }
}
