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
            new CommandCapability(
                name: 'make:module',
                kind: 'generator',
                summary: 'Scaffold a new module with the standard directory structure (10 directories).',
                use_when: 'Creating a new module from scratch. Eliminates wrong-directory mistakes.',
                avoid_when: 'The module already exists.',
                required_inputs: [
                    'name' => ['type' => 'string', 'description' => 'Module name (e.g., Catalog)'],
                ],
                optional_inputs: [
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned directories without creating', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'directories' => 'src/modules/{Module}/ with Application/{Payload,Handler,Resource,View,Command}/ and Domain/{Service,Contract,Event,Model}/',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints'],
                follow_up: ['make:page', 'make:service', 'make:contract'],
            ),
            new CommandCapability(
                name: 'make:service',
                kind: 'generator',
                summary: 'Scaffold a new service class with #[AsService] in Domain/Service/.',
                use_when: 'Creating a service that needs to be injectable via #[InjectAsReadonly].',
                avoid_when: 'The service already exists or you need a contract implementation (use make:contract instead).',
                required_inputs: [
                    'module' => ['type' => 'string', 'description' => 'Target module name'],
                    'name' => ['type' => 'string', 'description' => 'Service class name (e.g., PricingCalculator)'],
                ],
                optional_inputs: [
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned files without writing', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'service_class' => 'src/modules/{Module}/Domain/Service/{Name}.php',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints'],
                follow_up: [],
            ),
            new CommandCapability(
                name: 'make:event-listener',
                kind: 'generator',
                summary: 'Scaffold a new event listener with #[AsEventListener] and correct execution mode.',
                use_when: 'Reacting to a domain event (Sync, Async, or Queued).',
                avoid_when: 'The listener already exists or you need to modify an existing one.',
                required_inputs: [
                    'module' => ['type' => 'string', 'description' => 'Target module name'],
                    'name' => ['type' => 'string', 'description' => 'Listener class name (e.g., SendWelcomeEmail)'],
                    'event' => ['type' => 'string', 'description' => 'Event class name to listen for (e.g., UserRegistered)'],
                    'execution' => ['type' => 'string', 'description' => 'Execution mode: Sync, Async, or Queued'],
                ],
                optional_inputs: [
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned files without writing', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'listener_class' => 'src/modules/{Module}/Application/Handler/DomainListener/{Name}.php',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints'],
                follow_up: [],
            ),
            new CommandCapability(
                name: 'make:contract',
                kind: 'generator',
                summary: 'Scaffold a service contract: interface in Domain/Contract/ + implementation with #[SatisfiesServiceContract] in Domain/Service/.',
                use_when: 'Creating an interface that can have multiple implementations across modules.',
                avoid_when: 'You need a simple service without interface (use make:service instead).',
                required_inputs: [
                    'module' => ['type' => 'string', 'description' => 'Target module name'],
                    'name' => ['type' => 'string', 'description' => 'Contract name (e.g., PaymentGateway — Interface suffix added automatically)'],
                    'implementation' => ['type' => 'string', 'description' => 'Implementation class name (e.g., StripePaymentGateway)'],
                ],
                optional_inputs: [
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned files without writing', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'interface' => 'src/modules/{Module}/Domain/Contract/{Name}Interface.php',
                    'implementation' => 'src/modules/{Module}/Domain/Service/{Implementation}.php',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints'],
                follow_up: ['contracts:list'],
            ),
            new CommandCapability(
                name: 'make:command',
                kind: 'generator',
                summary: 'Scaffold a new CLI command with #[AsCommand] and BaseCommand.',
                use_when: 'Creating a CLI command for a module (e.g., data import, maintenance task).',
                avoid_when: 'The command already exists or you need to modify an existing one.',
                required_inputs: [
                    'module' => ['type' => 'string', 'description' => 'Target module name'],
                    'name' => ['type' => 'string', 'description' => 'Command class name (e.g., ImportUsers — Command suffix added automatically)'],
                    'command-name' => ['type' => 'string', 'description' => 'CLI command identifier (e.g., users:import)'],
                    'description' => ['type' => 'string', 'description' => 'Human-readable command description'],
                ],
                optional_inputs: [
                    'dry-run' => ['type' => 'flag', 'description' => 'Show planned files without writing', 'default' => false],
                    'force' => ['type' => 'flag', 'description' => 'Overwrite existing files', 'default' => false],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON GenerationResult', 'default' => false],
                    'llm-hints' => ['type' => 'flag', 'description' => 'Output LLM hints envelope', 'default' => false],
                ],
                outputs: [
                    'command_class' => 'src/modules/{Module}/Application/Command/{Name}Command.php',
                ],
                supports: ['--dry-run', '--force', '--json', '--llm-hints'],
                follow_up: [],
            ),
            new CommandCapability(
                name: 'describe:module',
                kind: 'introspection',
                summary: 'Show full module structure: payloads, handlers, resources, services, contracts, events, listeners, commands, templates.',
                use_when: 'Understanding a module before modifying it. Replaces 5-10 manual glob/grep queries.',
                avoid_when: 'You already know the module structure.',
                required_inputs: [
                    'name' => ['type' => 'string', 'description' => 'Module name (e.g., Catalog)'],
                ],
                optional_inputs: [
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON (semitexa-dev.module-description/v1)', 'default' => false],
                ],
                outputs: [
                    'description' => 'Lists all classes and templates organized by category',
                ],
                supports: ['--json'],
                follow_up: [],
            ),
            new CommandCapability(
                name: 'describe:route',
                kind: 'introspection',
                summary: 'Show the full chain for one route: payload → handler → resource → template → auth status.',
                use_when: 'Debugging or modifying an endpoint. Replaces 4-6 manual file lookups.',
                avoid_when: 'You need to list all routes (use routes:list instead).',
                required_inputs: [
                    'path' => ['type' => 'string', 'description' => 'Route path (e.g., /pricing or /api/users/{id})'],
                ],
                optional_inputs: [
                    'method' => ['type' => 'string', 'description' => 'HTTP method (default: GET)'],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON (semitexa-dev.route-description/v1)', 'default' => false],
                ],
                outputs: [
                    'description' => 'Full chain: payload class + file, handler class + file + execution mode, resource class + file, template path, auth status',
                ],
                supports: ['--json'],
                follow_up: [],
            ),
            new CommandCapability(
                name: 'describe:project',
                kind: 'introspection',
                summary: 'Show high-level project overview: all modules with route/service/contract/listener counts.',
                use_when: 'Starting a new session — understand the project before diving into code. Run first.',
                avoid_when: 'You already know the project structure from a recent session.',
                optional_inputs: [
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON (semitexa-dev.project-description/v1)', 'default' => false],
                ],
                outputs: [
                    'description' => 'Module list with type, extends, and counts for routes/services/contracts/listeners/events/commands',
                ],
                supports: ['--json'],
                follow_up: ['describe:module'],
            ),
            new CommandCapability(
                name: 'describe:event',
                kind: 'introspection',
                summary: 'Show all listeners for a given event (with execution mode, priority, module), or list all events.',
                use_when: 'Tracing event-driven behavior: who listens, in what order, sync/async/queued.',
                avoid_when: 'You already know the listeners for this event.',
                optional_inputs: [
                    'name' => ['type' => 'string', 'description' => 'Event class name (short or FQCN). Omit to list all events with listener counts.'],
                    'json' => ['type' => 'flag', 'description' => 'Output as JSON (semitexa-dev.event-description/v1 or events-list/v1)', 'default' => false],
                ],
                outputs: [
                    'description' => 'Per-event: listener classes with module, execution mode, priority. List mode: all events with listener counts.',
                ],
                supports: ['--json'],
                follow_up: ['make:event-listener'],
            ),
            new CommandCapability(
                name: 'logs:app',
                kind: 'introspection',
                summary: 'Read application log files with plain-text filtering, structured parsing, and around-timestamp context.',
                use_when: 'Inspecting recent application logs, narrowing by level/text, or grabbing context around a specific timestamp.',
                avoid_when: 'You need infrastructure logs outside var/log or already know the exact file and line offset.',
                optional_inputs: [
                    'file' => ['type' => 'string', 'description' => 'Log file alias: app, debug, session-debug, swoole', 'default' => 'app'],
                    'lines' => ['type' => 'int', 'description' => 'Number of lines from the end to inspect', 'default' => 100],
                    'grep' => ['type' => 'string', 'description' => 'Case-insensitive plain-text filter applied to raw lines'],
                    'level' => ['type' => 'string', 'description' => 'Filter structured entries by level (ERROR, WARNING, INFO, DEBUG)'],
                    'since' => ['type' => 'string', 'description' => 'Show entries since datetime or relative offset like -1h or -30m'],
                    'around' => ['type' => 'string', 'description' => 'Show entries around a timestamp'],
                    'context' => ['type' => 'int', 'description' => 'Number of lines on each side for --around mode', 'default' => 20],
                    'json' => ['type' => 'flag', 'description' => 'Output structured JSON', 'default' => false],
                    'list' => ['type' => 'flag', 'description' => 'List available log files with sizes', 'default' => false],
                ],
                outputs: [
                    'entries' => 'Plain-text lines or structured log entries parsed from var/log/*.log',
                ],
                supports: ['--json', '--list'],
                follow_up: [],
            ),
        ];
    }
}
