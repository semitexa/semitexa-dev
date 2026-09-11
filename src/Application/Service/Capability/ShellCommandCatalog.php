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
                required_inputs: [],
                optional_inputs: [],
                outputs: [],
                supports: [],
                follow_up: [],
            );
        }

        return array_values($out);
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
