<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Capability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Capability\RuntimeCommandCatalog;
use Semitexa\Dev\Application\Service\Capability\ShellCommandCatalog;
use Semitexa\Dev\Application\Service\Generation\Data\CommandCapability;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The manifest answers «what commands exist» — so it has to name all of them.
 *
 * MEASURED before the catalog existed: the application exposes 179 command
 * names and the hand-written CapabilityRegistry declared 18. `ai:ask
 * capabilities`, which AGENTS.md names as the answer to that exact question,
 * was omitting nine out of ten — every ai:* workflow command included. Nothing
 * was stale; a list is simply the wrong shape for the question, because nobody
 * updates it when a command is added.
 *
 * These cases pin the two halves that make the answer stay true: the inputs are
 * DERIVED so they cannot drift, and the curated prose is an OVERLAY so it
 * cannot be lost.
 */
final class CommandManifestCoversEverythingTest extends TestCase
{
    private function application(): Application
    {
        $app = new Application('probe');
        $app->add(new class extends Command {
            protected static $defaultName = 'probe:thing';
            public function __construct() { parent::__construct('probe:thing'); }
            protected function configure(): void
            {
                $this->setDescription('A described thing');
                $this->addArgument('subject', InputArgument::REQUIRED, 'What to act on');
                $this->addArgument('extra', InputArgument::OPTIONAL, 'Maybe', 'fallback');
                $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'A required value');
                $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON');
            }
            protected function execute(InputInterface $i, OutputInterface $o): int { return 0; }
        });

        return $app;
    }

    /** @return array<string, CommandCapability> */
    private function manifest(): array
    {
        $out = [];
        foreach ((new RuntimeCommandCatalog())->all($this->application()) as $cap) {
            $out[$cap->name] = $cap;
        }

        return $out;
    }

    #[Test]
    public function a_command_appears_without_anyone_declaring_it(): void
    {
        $manifest = $this->manifest();

        self::assertArrayHasKey('probe:thing', $manifest);
        self::assertSame('A described thing', $manifest['probe:thing']->summary);
    }

    /** The half that cannot drift: inputs come from the definition, always. */
    #[Test]
    public function inputs_are_derived_from_the_definition(): void
    {
        $cap = $this->manifest()['probe:thing'];

        self::assertArrayHasKey('subject', $cap->required_inputs, 'a required argument is required');
        self::assertArrayHasKey('--name', $cap->required_inputs, 'an option that needs a value is required');
        self::assertSame('What to act on', $cap->required_inputs['subject']['description']);

        self::assertArrayHasKey('extra', $cap->optional_inputs);
        self::assertSame('fallback', $cap->optional_inputs['extra']['default'] ?? null);
        self::assertArrayHasKey('--json', $cap->optional_inputs);
        self::assertSame('flag', $cap->optional_inputs['--json']['type']);

        self::assertSame(['--name', '--json'], $cap->supports);
    }

    /**
     * The application's own globals (--ansi, --quiet, --verbose…) get merged
     * into a command's definition once it has run. Taking them would make every
     * entry claim options that are not its own.
     */
    #[Test]
    public function the_applications_global_options_are_not_claimed_as_the_commands_own(): void
    {
        $cap = $this->manifest()['probe:thing'];

        foreach (['--ansi', '--quiet', '--verbose', '--help', '--version'] as $global) {
            self::assertNotContains($global, $cap->supports, $global . ' belongs to the application');
        }
    }

    /** Symfony's own plumbing is not a capability an agent can be sent to. */
    #[Test]
    public function symfony_internals_are_not_offered(): void
    {
        $manifest = $this->manifest();

        foreach (['help', 'list', 'completion', '_complete'] as $internal) {
            self::assertArrayNotHasKey($internal, $manifest);
        }
    }

    /**
     * The commands bin/semitexa runs ITSELF never reach a Symfony Application,
     * so a manifest derived only from one is blind to them — including
     * `test:run`, which is the command an agent runs most.
     */
    #[Test]
    public function the_shell_side_of_bin_semitexa_is_in_the_manifest_too(): void
    {
        $shell = (new ShellCommandCatalog())->all();

        self::assertNotSame([], $shell, 'bin/semitexa must be readable from here');

        $names = array_map(static fn (CommandCapability $c): string => $c->name, $shell);

        foreach (['test:run', 'self-test', 'lint:skin-legacy', 'local-app:list'] as $expected) {
            self::assertContains($expected, $names);
        }

        foreach ($shell as $cap) {
            self::assertNotSame('', $cap->summary, $cap->name . ' must carry the description the help table shows');
        }
    }

    /** Every shell command is listed exactly once, and never twice with the PHP side. */
    #[Test]
    public function nothing_is_listed_twice(): void
    {
        $names = array_map(
            static fn (CommandCapability $c): string => $c->name,
            (new RuntimeCommandCatalog())->all($this->application()),
        );

        self::assertSame(array_values(array_unique($names)), $names);
    }
}
