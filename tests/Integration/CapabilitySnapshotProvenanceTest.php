<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphMechanismsCommand;
use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A snapshot has to say that it is one.
 *
 * The shipped capability index is taken at the version of `semitexa/dev` that
 * carries it, so a package released afterwards is simply absent from it. The
 * index file already records `generated_at` — {@see CapabilityIndex::build()}
 * even says why: "an index is a snapshot, and a silent snapshot is the failure
 * mode". But the agent-facing surface dropped it: `dev:graph:mechanisms` read
 * `$index['capabilities']` and emitted `artifact`, `count`, `mechanisms` only.
 *
 * The result was worse than a missing timestamp. `ai:ask capabilities` stamps
 * the envelope with its OWN generation time, so a reader comparing the two
 * subjects sees a fresh timestamp next to a month-old snapshot and has no way
 * to tell them apart. An empty or short answer then reads as authoritative
 * rather than as stale.
 */
final class CapabilitySnapshotProvenanceTest extends TestCase
{
    #[Test]
    public function the_built_envelope_records_which_dev_produced_it(): void
    {
        $payload = CapabilityIndex::build([['id' => 'x.y']], ['semitexa/dev']);

        self::assertArrayHasKey('generated_at', $payload);
        self::assertArrayHasKey(
            'source_version',
            $payload,
            'the envelope must name the semitexa/dev that generated it — "when" alone does not say "from what"',
        );
        self::assertIsString($payload['source_version']);
        self::assertNotSame('', $payload['source_version']);
    }

    #[Test]
    public function the_shipped_index_carries_its_own_provenance(): void
    {
        $index = CapabilityIndex::read(CapabilityIndex::path(dirname(__DIR__, 3)));

        self::assertIsArray($index, 'the shipped index must be readable — everything below reads it');
        self::assertArrayHasKey('generated_at', $index);
        self::assertArrayHasKey('source_version', $index, 'the shipped index predates the provenance field — regenerate it');
    }

    #[Test]
    public function the_mechanisms_envelope_surfaces_the_snapshot_boundary(): void
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
        $tester->execute(['--json' => true]);

        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('mechanisms', $envelope);
        self::assertArrayHasKey(
            'snapshot',
            $envelope,
            'the answer merges a live catalog with a shipped snapshot; the reader must be told how old that snapshot is',
        );

        /** @var array<string, mixed> $snapshot */
        $snapshot = $envelope['snapshot'];
        self::assertArrayHasKey('generated_at', $snapshot);
        self::assertArrayHasKey('source_version', $snapshot);

        // Must be the INDEX's timestamp, not the moment this command ran —
        // stamping "now" is precisely what makes a stale snapshot look fresh.
        $index = CapabilityIndex::read(CapabilityIndex::path(dirname(__DIR__, 3)));
        self::assertIsArray($index);
        self::assertSame($index['generated_at'] ?? null, $snapshot['generated_at']);
    }
}
