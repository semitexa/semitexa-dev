<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Capability;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Generation\Data\CommandCapability;

/**
 * The commands `bin/semitexa` runs itself, which the PHP application cannot see.
 *
 * `bin/semitexa` dispatches two different things under one prompt: it proxies
 * most names into the app container, but about twenty it handles in shell —
 * `test:run`, `self-test`, `install`, the `local-app:` / `local-domain:` /
 * `local-router:` families, and the two skin guards. Those never reach a
 * Symfony Application, so {@see RuntimeCommandCatalog} is structurally blind to
 * them: an agent reading the manifest would be told `test:run` does not exist,
 * which is the command it runs most.
 *
 * Read from the script's OWN help table rather than restated here. The table is
 * what an operator sees when they type `bin/semitexa list`, so deriving from it
 * keeps the two answers identical by construction — the same reason
 * {@see RuntimeCommandCatalog} takes its summaries from `getDescription()`
 * instead of from a curated copy.
 */
final class ShellCommandCatalog
{
    /**
     * `printf "  %-32s %s\n" "name"  "description"`, which is the one shape the
     * help table uses. The name may carry an argument hint (`test:run [-- args]`,
     * `local-app:remove <id>`); the command is the part before the first space.
     */
    private const HELP_LINE = '/printf\s+"\s+%-32s\s+%s\\\\n"\s+"([^"]+)"\s+"([^"]+)"/';

    /** Handled by the script but not a command an agent should be told to run. */
    private const NOT_A_CAPABILITY = ['list'];

    /** @return list<CommandCapability> */
    public function all(?string $scriptPath = null): array
    {
        $script = $scriptPath ?? ProjectRoot::get() . '/bin/semitexa';
        $body = is_file($script) ? @file_get_contents($script) : false;

        if ($body === false) {
            return [];
        }

        $dispatched = $this->dispatchedNames($body);
        $out = [];

        if (preg_match_all(self::HELP_LINE, $body, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        foreach ($matches as [, $label, $description]) {
            $name = strstr($label, ' ', true) ?: $label;
            [$required, $optional] = self::inputsFrom($label, $name);

            // Only what the script actually runs itself. The help table also
            // lists proxied names, and those are the PHP application's to
            // describe — two entries for one command is how the answers start
            // disagreeing.
            if (!in_array($name, $dispatched, true) || in_array($name, self::NOT_A_CAPABILITY, true)) {
                continue;
            }

            $out[$name] = new CommandCapability(
                name: $name,
                kind: 'shell',
                summary: $description,
                use_when: '',
                avoid_when: '',
                required_inputs: $required,
                optional_inputs: $optional,
                outputs: [],
                supports: [],
                follow_up: [],
            );
        }

        return array_values($out);
    }

    /**
     * The inputs the help label itself declares.
     *
     * The label is the command name followed by the script's own argument
     * syntax — `local-app:remove <id>`, `local-domain:mode [dns|hosts]`,
     * `test:run [-- phpunit-args]`. Everything after the name used to be cut
     * off, so five of the twenty-five shell commands were published as taking
     * no inputs at all: an agent reading the manifest was told
     * `local-app:remove` needs nothing, and would run it without the id it
     * cannot work without.
     *
     * The hint text is kept verbatim as the input's name rather than translated
     * into something tidier. `bin/semitexa --help` is the other place an
     * operator reads this, and a manifest that renamed `<domain.test>` to
     * `domain` would be a second answer to the same question.
     *
     * @return array{array<string, array{type: string, description: string}>, array<string, array{type: string, description: string}>}
     */
    private static function inputsFrom(string $label, string $name): array
    {
        $syntax = trim(substr($label, strlen($name)));

        if ($syntax === '') {
            return [[], []];
        }

        $required = [];
        $optional = [];

        preg_match_all('/<([^>]+)>|\[([^\]]+)\]/', $syntax, $hints, PREG_SET_ORDER);

        foreach ($hints as $hint) {
            $mandatory = ($hint[1] ?? '') !== '';
            $text = $mandatory ? $hint[1] : ($hint[2] ?? '');

            if ($text === '') {
                continue;
            }

            $meta = ['type' => self::hintType($text), 'description' => self::hintDescription($text)];

            if ($mandatory) {
                $required[$text] = $meta;
            } else {
                $optional[$text] = $meta;
            }
        }

        return [$required, $optional];
    }

    private static function hintType(string $text): string
    {
        return match (true) {
            str_contains($text, '|') => 'enum',
            str_starts_with($text, '--') => 'passthrough',
            default => 'string',
        };
    }

    private static function hintDescription(string $text): string
    {
        if (str_contains($text, '|')) {
            return 'One of: ' . implode(', ', array_map('trim', explode('|', $text)));
        }

        if (str_starts_with($text, '--')) {
            return 'Everything after `--` is handed to the underlying tool unchanged.';
        }

        return 'Declared by bin/semitexa as `' . $text . '`.';
    }

    /**
     * The names the script's own dispatch table answers for.
     *
     * @return list<string>
     */
    private function dispatchedNames(string $body): array
    {
        if (preg_match_all('/^\s{4}([a-z][a-z0-9:_-]*)\)\s+cmd_/m', $body, $m) === 0) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }
}
