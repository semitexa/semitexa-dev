<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Recipe;

/**
 * Static catalog of canonical task patterns. Phase 2 ships ~10 recipes covering
 * the most common operations agents perform on a Semitexa codebase. Add a
 * recipe by appending to {@see self::recipes()} — no new file is required.
 */
final class RecipeRegistry
{
    /** @var list<Recipe>|null */
    private static ?array $cache = null;

    /**
     * @return list<Recipe>
     */
    public static function all(): array
    {
        return self::$cache ??= self::recipes();
    }

    public static function find(string $id): ?Recipe
    {
        foreach (self::all() as $recipe) {
            if ($recipe->id === $id) {
                return $recipe;
            }
        }
        return null;
    }

    /**
     * @return list<Recipe>
     */
    private static function recipes(): array
    {
        return [
            new Recipe(
                id: 'add_authenticated_json_route',
                label: 'Add authenticated JSON route',
                summary: 'Create Payload + Handler + Resource for an auth-required JSON endpoint.',
                keywords: ['json', 'api', 'endpoint', 'route', 'auth', 'authenticated', 'token', 'bearer'],
                verbs: ['add', 'create', 'expose', 'introduce'],
                generator_chain: ['make:payload', 'make:handler', 'make:resource'],
                context_signals: ['Payload', 'Handler', 'Resource', 'AuthHandler', 'Auth\\'],
                arg_hints: [
                    '--module' => 'Target module (existing module name preferred).',
                    '--name'   => 'Endpoint name in StudlyCase (e.g. GetInvoice).',
                    '--path'   => 'HTTP route path with placeholders (e.g. /billing/invoices/{id}).',
                ],
                default_risk: 'low',
            ),
            new Recipe(
                id: 'add_html_page',
                label: 'Add HTML page',
                summary: 'Scaffold a complete server-rendered page (Payload + Handler + Resource + Twig template).',
                keywords: ['page', 'view', 'twig', 'html', 'template', 'screen'],
                verbs: ['add', 'create', 'render', 'show', 'display'],
                generator_chain: ['make:page'],
                context_signals: ['Resource\\Response', 'View\\templates', 'HtmlResponse'],
                arg_hints: [
                    '--module' => 'Target module (existing module name preferred).',
                    '--name'   => 'Page name in StudlyCase (e.g. Dashboard).',
                ],
                default_risk: 'low',
            ),
            new Recipe(
                id: 'add_event_listener',
                label: 'Add event listener',
                summary: 'Wire a listener for an existing domain event.',
                keywords: ['event', 'listener', 'subscribe', 'react', 'handler', 'domain', 'on'],
                verbs: ['add', 'listen', 'subscribe', 'react', 'handle'],
                generator_chain: ['make:event-listener'],
                context_signals: ['DomainListener', 'Event\\', 'AsEventListener'],
                arg_hints: [
                    '--module' => 'Module that will own the listener.',
                    '--name'   => 'Listener name in StudlyCase.',
                    '--event'  => 'Fully-qualified event class name to subscribe to.',
                ],
                default_risk: 'low',
            ),
            new Recipe(
                id: 'add_service',
                label: 'Add domain service',
                summary: 'Scaffold a #[AsService] class inside a module.',
                keywords: ['service', 'helper', 'logic', 'domain'],
                verbs: ['add', 'create', 'extract', 'introduce'],
                generator_chain: ['make:service'],
                context_signals: ['Domain\\Service', 'AsService'],
                arg_hints: [
                    '--module' => 'Module to host the service.',
                    '--name'   => 'Service class name in StudlyCase.',
                ],
                default_risk: 'low',
            ),
            new Recipe(
                id: 'extract_service_contract',
                label: 'Extract service behind a contract',
                summary: 'Create a contract interface plus an implementation under #[SatisfiesServiceContract].',
                keywords: ['contract', 'interface', 'abstraction', 'extract', 'protocol'],
                verbs: ['extract', 'introduce', 'abstract', 'wrap'],
                generator_chain: ['make:contract'],
                context_signals: ['Domain\\Contract', 'SatisfiesServiceContract'],
                arg_hints: [
                    '--module' => 'Module that owns the contract.',
                    '--name'   => 'Contract base name (Foo → FooInterface + Foo).',
                ],
                default_risk: 'medium',
            ),
            new Recipe(
                id: 'add_cli_command',
                label: 'Add CLI command',
                summary: 'Scaffold a Symfony command marked with #[AsCommand].',
                keywords: ['cli', 'command', 'console', 'bin', 'script'],
                verbs: ['add', 'create', 'expose', 'introduce'],
                generator_chain: ['make:command'],
                context_signals: ['Application\\Console', 'Console\\Command', 'AsCommand'],
                arg_hints: [
                    '--module' => 'Module that will own the command.',
                    '--name'   => 'Command class name (StudlyCase).',
                    '--cli'    => 'Command CLI name (e.g. semitexa:foo:bar).',
                ],
                default_risk: 'low',
            ),
            new Recipe(
                id: 'add_module',
                label: 'Bootstrap a new module',
                summary: 'Create the standard module directory layout (Application/Domain/Resource/Payload).',
                keywords: ['module', 'bounded', 'package', 'context'],
                verbs: ['add', 'create', 'bootstrap', 'introduce', 'start'],
                generator_chain: ['make:module'],
                context_signals: ['src/modules/'],
                arg_hints: [
                    '--name' => 'Module name in StudlyCase.',
                ],
                default_risk: 'medium',
            ),
            new Recipe(
                id: 'add_payload_field',
                label: 'Add a field to an existing payload',
                summary: 'Add a property to an existing Payload DTO and re-run lint to verify the contract is intact.',
                keywords: ['payload', 'field', 'property', 'parameter', 'dto'],
                verbs: ['add', 'extend', 'augment'],
                generator_chain: [],
                context_signals: ['Payload\\Request'],
                arg_hints: [
                    '--module' => 'Module hosting the payload.',
                    '--name'   => 'Payload class to modify (no Payload suffix).',
                ],
                default_risk: 'low',
            ),
            new Recipe(
                id: 'rename_symbol',
                label: 'Rename a class, method, or property',
                summary: 'Rename a symbol and update all callers across the project.',
                keywords: ['rename', 'reword', 'rebrand'],
                verbs: ['rename', 'reword', 'change', 'rebrand'],
                generator_chain: [],
                context_signals: [],
                arg_hints: [
                    '--from' => 'Existing symbol name.',
                    '--to'   => 'Desired symbol name.',
                ],
                default_risk: 'medium',
            ),
            new Recipe(
                id: 'fix_template_text',
                label: 'Fix copy in a Twig template',
                summary: 'Edit Twig markup or copy in a template file. No code changes.',
                keywords: ['typo', 'copy', 'text', 'twig', 'template', 'spelling', 'wording'],
                verbs: ['fix', 'change', 'update', 'reword'],
                generator_chain: [],
                context_signals: ['View\\templates'],
                arg_hints: [],
                default_risk: 'low',
            ),
        ];
    }
}
