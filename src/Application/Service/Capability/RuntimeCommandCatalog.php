<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Capability;

use Semitexa\Dev\Application\Service\Generation\Data\CommandCapability;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * The command manifest, derived from the commands that actually exist.
 *
 * {@see CapabilityRegistry} is a hand-written list, and a hand-written list of
 * what a program can do drifts the moment the program grows. MEASURED before
 * this class existed: the application exposes 179 commands and the registry
 * declared 18 — so `ai:ask capabilities`, which `AGENTS.md` names as the answer
 * to "what COMMANDS exist to run", omitted nine out of ten of them. Every
 * `ai:*` workflow command the manual tells an agent to use was missing:
 * ai:orient, ai:task, ai:verify, ai:epic, ai:work, ai:context, ai:plan,
 * ai:trace, ai:report, ai:observe, ai:invoke, ai:backlog. Nothing was stale —
 * the list was simply incomplete, which is the failure mode a list has.
 *
 * So the shape here is a derived spine with a curated overlay:
 *
 *   * the NAME, the SUMMARY and every INPUT come from the command's own
 *     definition, always. They cannot drift, because there is nowhere for them
 *     to drift from.
 *   * `use_when`, `avoid_when`, `outputs`, `follow_up` and `kind` come from
 *     {@see CapabilityRegistry} when it has an entry. Those are judgement — when
 *     NOT to reach for something is not in a signature — and a generator cannot
 *     invent them.
 *
 * A command with no curated entry appears with empty guidance rather than with
 * invented guidance. That absence is the honest backlog of what still needs
 * writing, and it is visible in the manifest instead of hidden by omission.
 */
final class RuntimeCommandCatalog
{
    /**
     * Symfony's own plumbing. An agent cannot usefully be told to run these,
     * and `_complete` is not addressable by a human at all.
     */
    private const NOT_OURS = ['help', 'list', 'completion', '_complete'];

    /**
     * What a namespace is FOR, when nobody has said otherwise.
     *
     * Coarse on purpose: the kind is a filter, not a description, and a wrong
     * guess here is worse than a vague one. A curated entry overrides it.
     */
    private const KIND_BY_PREFIX = [
        'make' => 'generator',
        'ai' => 'introspection',
        'dev' => 'introspection',
        'lint' => 'verification',
        'logs' => 'introspection',
        'test' => 'verification',
    ];

    /**
     * Every command an agent can run here, from both sides of `bin/semitexa`.
     *
     * @return list<CommandCapability>
     */
    public function all(Application $application): array
    {
        $curated = [];
        foreach (CapabilityRegistry::all() as $entry) {
            $curated[$entry->name] = $entry;
        }

        $out = [];
        foreach ($application->all() as $name => $command) {
            // `all()` keys aliases to the same object; the command's own name is
            // the one an agent should be told to type.
            if ($command->getName() !== $name || !$this->isAddressable($name, $command)) {
                continue;
            }

            $out[] = $this->describe($command, $curated[$name] ?? null);
        }

        // The shell side, which no Application can see. Added only where the
        // PHP side has nothing of that name: `server:restart` exists on both,
        // and the one that actually reports its own definition is the better
        // answer.
        $known = array_map(static fn (CommandCapability $c): string => $c->name, $out);

        foreach ((new ShellCommandCatalog())->all() as $shell) {
            if (!in_array($shell->name, $known, true)) {
                $out[] = $shell;
            }
        }

        usort($out, static fn (CommandCapability $a, CommandCapability $b): int => strcmp($a->name, $b->name));

        return $out;
    }

    private function isAddressable(string $name, Command $command): bool
    {
        return !in_array($name, self::NOT_OURS, true) && $command->isEnabled() && !$command->isHidden();
    }

    private function describe(Command $command, ?CommandCapability $curated): CommandCapability
    {
        $name = (string) $command->getName();
        [$required, $optional, $supports] = $this->inputs($command);

        return new CommandCapability(
            name: $name,
            kind: $curated?->kind ?? $this->kindOf($name),
            // The description the command declares. A curated summary that
            // disagreed with it would be a second answer to the same question.
            summary: $command->getDescription() !== '' ? $command->getDescription() : ($curated?->summary ?? ''),
            use_when: $curated?->use_when ?? '',
            avoid_when: $curated?->avoid_when ?? '',
            required_inputs: $required,
            optional_inputs: $optional,
            outputs: $curated?->outputs ?? [],
            supports: $supports,
            follow_up: $curated?->follow_up ?? [],
        );
    }

    /**
     * Arguments and options, as the command defines them.
     *
     * `getNativeDefinition()`, not `getDefinition()`: the latter has the
     * application's global options merged in by the time a command has been
     * run, so every entry would claim to support `--ansi`, `--quiet` and
     * `--verbose` as though they were its own.
     *
     * @return array{array<string, array{type: string, description: string}>, array<string, array{type: string, description: string, default?: mixed}>, list<string>}
     */
    private function inputs(Command $command): array
    {
        $required = [];
        $optional = [];
        $supports = [];

        foreach ($command->getNativeDefinition()->getArguments() as $argument) {
            $meta = [
                'type' => $argument->isArray() ? 'list' : 'string',
                'description' => $argument->getDescription(),
            ];

            if ($argument->isRequired()) {
                $required[$argument->getName()] = $meta;
                continue;
            }

            $default = $argument->getDefault();
            $optional[$argument->getName()] = $default === null ? $meta : $meta + ['default' => $default];
        }

        foreach ($command->getNativeDefinition()->getOptions() as $option) {
            $supports[] = '--' . $option->getName();

            $meta = [
                'type' => $this->typeOf($option),
                'description' => $option->getDescription(),
            ];

            // An option is NEVER a required input. Symfony has no such concept:
            // `VALUE_REQUIRED` says that an option, IF SUPPLIED, must carry a
            // value — it does not say the option must be supplied. Reading it
            // as a requirement made the manifest wrong in both directions at
            // once: `probe:thing` was reported as requiring `--name` when it
            // does not, while `make:payload`, which genuinely refuses to run
            // without module, name, path, method and response, had its
            // `--graphql-field` listed beside them as though it were the same
            // kind of obligation. A caller reading this to decide what to pass
            // was being told to pass things that are optional and given no way
            // to tell which ones actually matter.
            //
            // The value requirement is still worth recording, so it is recorded
            // as what it is — a property of the option, not of the command.
            if ($option->isValueRequired()) {
                $meta['value'] = 'required';
            }

            $default = $option->getDefault();
            $optional['--' . $option->getName()] = $default === null || $default === false
                ? $meta
                : $meta + ['default' => $default];
        }

        return [$required, $optional, $supports];
    }

    private function typeOf(InputOption $option): string
    {
        return match (true) {
            $option->isArray() => 'list',
            !$option->acceptValue() => 'flag',
            default => 'string',
        };
    }

    private function kindOf(string $name): string
    {
        $prefix = str_contains($name, ':') ? strstr($name, ':', true) : $name;

        return self::KIND_BY_PREFIX[$prefix] ?? 'operation';
    }
}
