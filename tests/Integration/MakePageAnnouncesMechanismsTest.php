<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Console\Command\MakePageCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The generator is the only capability surface that speaks BEFORE the mistake.
 *
 * The catalog answers when someone thinks to ask; the lint objects once the work
 * is done. `make:page` runs at the moment the shape of a page is being decided,
 * and whatever it emits becomes the pattern copied for the rest of the project —
 * so a generator that mentions no mechanism teaches that there are none. That
 * was the state measured at the start of this epic: zero mentions.
 */
final class MakePageAnnouncesMechanismsTest extends TestCase
{
    private function scaffold(bool $hints = false): CommandTester
    {
        $command = new MakePageCommand();

        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $p = new ReflectionProperty(MakePageCommand::class, 'classDiscovery');
        $p->setAccessible(true);
        $p->setValue($command, $discovery);

        // The framework's console Application assigns the name from
        // #[AsCommand] at registration; a directly constructed command has none.
        $command->setName('make:page');

        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($app->find('make:page'));
        // Dry run is the default: nothing is written by this test.
        $tester->execute(array_filter([
            '--module' => 'UiPlayground',
            '--name' => 'MechanismNudgeProbe',
            '--path' => '/mechanism-nudge-probe',
            '--method' => 'GET',
            '--llm-hints' => $hints ?: null,
        ]));

        return $tester;
    }

    #[Test]
    public function the_human_output_names_mechanisms_and_where_to_read_more(): void
    {
        $display = $this->scaffold()->getDisplay();

        self::assertStringContainsString('the framework already does', $display);
        self::assertStringContainsString('ssr.deferred', $display);
        self::assertStringContainsString('ai:ask mechanisms', $display);
    }

    #[Test]
    public function the_llm_hints_envelope_carries_the_same_mechanisms(): void
    {
        // Agents read the envelope, not the prose. A nudge only humans can see
        // would miss the audience this epic is about.
        /** @var array<string, mixed> $hints */
        $hints = json_decode(trim($this->scaffold(hints: true)->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('mechanisms', $hints, 'the hints envelope must offer mechanisms');
        $ids = array_column((array) $hints['mechanisms'], 'capability');

        self::assertContains('ssr.deferred', $ids);
        self::assertNotEmpty($ids);
    }

    #[Test]
    public function each_offered_mechanism_states_both_when_to_use_and_when_not_to(): void
    {
        // Offering only upsides is how a generator talks someone into deferring
        // a fast region. The counter-advice travels with the offer.
        /** @var array<string, mixed> $hints */
        $hints = json_decode(trim($this->scaffold(hints: true)->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        foreach ((array) $hints['mechanisms'] as $mechanism) {
            self::assertNotSame('', trim((string) $mechanism['use_when']), $mechanism['capability']);
            self::assertNotSame('', trim((string) $mechanism['avoid_when']), $mechanism['capability']);
        }
    }

    #[Test]
    public function the_offer_is_derived_rather_than_written_into_the_generator(): void
    {
        // A list hard-coded here would go stale the day a capability is added,
        // and a consumer project would never be offered it at all. Every id
        // offered must exist as a real declared capability.
        /** @var array<string, mixed> $hints */
        $hints = json_decode(trim($this->scaffold(hints: true)->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        foreach ((array) $hints['mechanisms'] as $mechanism) {
            self::assertTrue(
                class_exists((string) $mechanism['attribute']),
                'offered capability points at a real attribute class: ' . $mechanism['attribute'],
            );
        }
    }
}
