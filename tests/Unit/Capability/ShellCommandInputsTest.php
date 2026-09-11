<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Capability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Capability\ShellCommandCatalog;

/**
 * The shell manifest publishes the arguments `bin/semitexa --help` declares.
 *
 * The catalog reads the script's own help table, and it used to keep only the
 * part of each label before the first space — so `local-app:remove <id>` became
 * `local-app:remove` and was published as taking no inputs. Five of the
 * twenty-five shell commands were described that way. An agent reading the
 * manifest to decide what to pass was told, with no hedging, that a command
 * which cannot work without an id needs nothing.
 */
final class ShellCommandInputsTest extends TestCase
{
    private string $script = '';

    protected function setUp(): void
    {
        $this->script = sys_get_temp_dir() . '/semitexa-shell-' . bin2hex(random_bytes(6));
        file_put_contents($this->script, <<<'SH'
#!/usr/bin/env bash
case "$1" in
    local-app:remove) cmd_local_app_remove "$2" ;;
    local-domain:mode) cmd_local_domain_mode "$2" ;;
    test:run) cmd_test_run "${@:2}" ;;
    doctor) cmd_doctor ;;
esac
usage() {
  printf "  %-32s  %s\n" "local-app:remove <id>" "Remove a registered local app"
  printf "  %-32s  %s\n" "local-domain:mode [dns|hosts]" "Show or switch resolution mode"
  printf "  %-32s  %s\n" "test:run [-- phpunit-args]" "Run the test suite"
  printf "  %-32s  %s\n" "doctor" "Check the environment"
}
SH);
    }

    protected function tearDown(): void
    {
        @unlink($this->script);
    }

    /** @return array<string, \Semitexa\Dev\Application\Service\Generation\Data\CommandCapability> */
    private function manifest(): array
    {
        $out = [];
        foreach ((new ShellCommandCatalog())->all($this->script) as $capability) {
            $out[$capability->name] = $capability;
        }

        return $out;
    }

    /** `<x>` is an argument the command refuses to run without. */
    #[Test]
    public function an_angle_bracket_hint_is_a_required_input(): void
    {
        $capability = $this->manifest()['local-app:remove'];

        self::assertSame(['id'], array_keys($capability->required_inputs));
        self::assertSame('string', $capability->required_inputs['id']['type']);
        self::assertSame([], $capability->optional_inputs);
    }

    /** `[x]` is optional, and a `|` in it enumerates what it accepts. */
    #[Test]
    public function a_square_bracket_hint_is_optional_and_a_pipe_enumerates_it(): void
    {
        $capability = $this->manifest()['local-domain:mode'];

        self::assertSame([], $capability->required_inputs);
        self::assertSame(['dns|hosts'], array_keys($capability->optional_inputs));
        self::assertSame('enum', $capability->optional_inputs['dns|hosts']['type']);
        self::assertSame('One of: dns, hosts', $capability->optional_inputs['dns|hosts']['description']);
    }

    /** Everything after `--` goes to the underlying tool and is not ours to describe. */
    #[Test]
    public function a_pass_through_hint_is_marked_as_one(): void
    {
        $capability = $this->manifest()['test:run'];

        self::assertSame('passthrough', $capability->optional_inputs['-- phpunit-args']['type']);
    }

    /** A command whose label is just its name still declares nothing, and must say so. */
    #[Test]
    public function a_command_without_a_hint_declares_no_inputs(): void
    {
        $capability = $this->manifest()['doctor'];

        self::assertSame([], $capability->required_inputs);
        self::assertSame([], $capability->optional_inputs);
    }
}
