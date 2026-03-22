<?php

declare(strict_types=1);

namespace Semitexa\Dev\Capability;

use Semitexa\Dev\Generation\Data\CommandCapability;

final class CapabilityRegistry
{
    /** @return list<CommandCapability> */
    public static function all(): array
    {
        return [
            new CommandCapability(
                name: 'ai:capabilities',
                kind: 'introspection',
                summary: 'List all available generator commands with their inputs, outputs, and usage guidance.',
                use_when: 'Starting a new task that might involve code generation, or when unsure what generators exist.',
                avoid_when: 'You already know the exact command and flags to use.',
                outputs: ['manifest' => 'JSON capability manifest (semitexa.ai-capabilities/v1)'],
                supports: ['--json'],
            ),
            new CommandCapability(
                name: 'make:payload',
                kind: 'generator',
                summary: 'Scaffold a new Payload DTO class with route, validation, and response binding.',
                use_when: 'Creating a new HTTP endpoint that needs a request DTO.',
                avoid_when: 'The payload already exists or you need to modify an existing one.',
                required_inputs: [
                    'module' => ['type' => 'string', 'description' => 'Target module name (e.g., Website)'],
                    'name' => ['type' => 'string', 'description' => 'Payload name without suffix (e.g., Pricing)'],
                    'path' => ['type' => 'string', 'description' => 'Route path (e.g., /pricing)'],
                    'method' => ['type' => 'string', 'description' => 'HTTP method (GET, POST, etc.)'],
                    'response' => ['type' => 'string', 'description' => 'Response class name without suffix (e.g., Pricing)'],
                ],
                optional_inputs: [
                    'public' => ['type' => 'flag', 'description' => 'Add #[PublicEndpoint] attribute', 'default' => false],
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned files without writing', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'payload_class' => 'src/modules/{Module}/Application/Payload/Request/{Name}Payload.php',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints'],
                follow_up: ['make:handler', 'make:resource'],
            ),
            new CommandCapability(
                name: 'make:handler',
                kind: 'generator',
                summary: 'Scaffold a new Handler class bound to a Payload and Resource.',
                use_when: 'Creating the business logic handler for a payload.',
                avoid_when: 'The handler already exists or you need to modify an existing one.',
                required_inputs: [
                    'module' => ['type' => 'string', 'description' => 'Target module name'],
                    'name' => ['type' => 'string', 'description' => 'Handler name without suffix (e.g., Pricing)'],
                    'payload' => ['type' => 'string', 'description' => 'Payload class name without suffix'],
                    'resource' => ['type' => 'string', 'description' => 'Resource class name without suffix'],
                ],
                optional_inputs: [
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned files without writing', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'handler_class' => 'src/modules/{Module}/Application/Handler/PayloadHandler/{Name}Handler.php',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints'],
                follow_up: [],
            ),
            new CommandCapability(
                name: 'make:resource',
                kind: 'generator',
                summary: 'Scaffold a new Resource (Response) class with optional Twig template.',
                use_when: 'Creating a response class for an endpoint.',
                avoid_when: 'The resource already exists or you need to modify an existing one.',
                required_inputs: [
                    'module' => ['type' => 'string', 'description' => 'Target module name'],
                    'name' => ['type' => 'string', 'description' => 'Resource name without suffix (e.g., Pricing)'],
                    'handle' => ['type' => 'string', 'description' => 'Render handle name (kebab-case)'],
                ],
                optional_inputs: [
                    'template' => ['type' => 'string', 'description' => 'Custom Twig template path'],
                    'with-template' => ['type' => 'flag', 'description' => 'Generate a Twig template file', 'default' => false],
                    'with-assets' => ['type' => 'flag', 'description' => 'Generate CSS/JS/assets.json stubs', 'default' => false],
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned files without writing', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'resource_class' => 'src/modules/{Module}/Application/Resource/Response/{Name}Response.php',
                    'twig_template' => 'src/modules/{Module}/Application/View/templates/pages/{kebab-name}.html.twig (if --with-template)',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints', '--with-template', '--with-assets'],
                follow_up: [],
            ),
            new CommandCapability(
                name: 'make:page',
                kind: 'generator',
                summary: 'Scaffold a complete page: Payload + Handler + Resource + optional template and assets.',
                use_when: 'Creating a new page from scratch — the all-in-one command.',
                avoid_when: 'You only need one of the three (payload/handler/resource). Use the specific command instead.',
                required_inputs: [
                    'module' => ['type' => 'string', 'description' => 'Target module name'],
                    'name' => ['type' => 'string', 'description' => 'Page name (e.g., Pricing)'],
                    'path' => ['type' => 'string', 'description' => 'Route path (e.g., /pricing)'],
                    'method' => ['type' => 'string', 'description' => 'HTTP method'],
                ],
                optional_inputs: [
                    'layout' => ['type' => 'string', 'description' => 'Layout template name'],
                    'public' => ['type' => 'flag', 'description' => 'Add #[PublicEndpoint]', 'default' => false],
                    'with-assets' => ['type' => 'flag', 'description' => 'Generate CSS/JS/assets.json stubs', 'default' => false],
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned files without writing', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'payload_class' => 'src/modules/{Module}/Application/Payload/Request/{Name}Payload.php',
                    'handler_class' => 'src/modules/{Module}/Application/Handler/PayloadHandler/{Name}Handler.php',
                    'resource_class' => 'src/modules/{Module}/Application/Resource/Response/{Name}Response.php',
                    'twig_template' => 'src/modules/{Module}/Application/View/templates/pages/{kebab-name}.html.twig',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints', '--with-assets'],
                follow_up: [],
            ),
        ];
    }
}
