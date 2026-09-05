<?php

declare(strict_types=1);

/**
 * Executable Semitexa module / package structure specification.
 *
 * **This file is the authoritative spec.** The prose document
 * `packages/semitexa-docs/docs/MODULE_STRUCTURE.md` mirrors this file for
 * human readers — when this spec changes, the doc must be updated to match.
 * `bin/semitexa ai:verify` loads this spec via {@see ModuleStructureSpecLoader}
 * and treats every directory and file inside an affected module as **required
 * to be explicitly allowed by an entry below**. Anything not declared here
 * fails validation with a `module_structure.*` violation.
 *
 * Edit rules:
 *
 *   - Add a path: include both its `ModuleStructureRule` and the parent's
 *     `allowedDirectories` entry. A new path needs a parent declaration to
 *     even be reachable.
 *   - Permit feature grouping (sub-features like `Customer/`, `Order/`):
 *     set `allowFeatureGrouping: true` on the leaf where grouping is
 *     architecturally meaningful (typically `Service/`, `Repository/`,
 *     `Model/`, `Console/Command/`, `Resource/Response/`).
 *   - Permit raw files at a leaf: set `allowAnyFile: true`. Otherwise the
 *     leaf rejects any direct file with `module_structure.invalid_root_file`.
 *
 * Do **not** infer rules from existing repository drift. The spec encodes
 * the *approved* architecture, not the current state.
 */

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\FilePlacementRule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpec;

$rule = static fn (...$args): ModuleStructureRule => new ModuleStructureRule(...$args);

/**
 * Officially supported Semitexa ORM database adapters.
 *
 * Source of truth: the adapter classes shipped by `packages/semitexa-orm/`
 * (`MysqlAdapter`, `SqliteAdapter`) and the runtime guard in
 * `Semitexa\Orm\OrmManager` that rejects every other driver string with
 * `"Unsupported DB driver. Expected 'mysql' or 'sqlite'."`.
 *
 * Directory naming follows the established convention used by every existing
 * package's `Application/Db/` tree (see `packages/semitexa-api/src/Application/Db/MySQL/`,
 * `packages/semitexa-webhooks/src/Application/Db/MySQL/`, etc.) — product
 * capitalization (`MySQL`, `SQLite`), not the PHP class spelling
 * (`Mysql`, `Sqlite`). When a new adapter ships in `semitexa-orm`, add it
 * here AND update `packages/semitexa-docs/docs/MODULE_STRUCTURE.md`.
 *
 * NOT a wildcard. NOT inferred at runtime. The list is authoritative.
 *
 * @var list<string>
 */
$persistenceAdapters = ['MySQL', 'SQLite'];

$codeRoot = [
    // Module root — the synthetic `top_level` path. Strict allowlist of
    // architectural top-level directories. Application modules use the
    // canonical core (`Application`, `Domain`, `Context`, `Configuration`,
    // `Update`, `Static`, `View`). Packages may additionally declare a
    // small, named set of framework-level layers — `Attribute/` (PHP
    // attributes), `Auth/`, `Discovery/`, `OpenApi/`, `Pipeline/` — each
    // listed in `packageOnlyDirectories` below so they trigger
    // `module_structure.invalid_layer` if they appear inside `src/modules/*`.
    // `Exception/` is the canonical home for package-wide exception classes
    // (allowed in both packages and application modules) — see the
    // leaf-files-only rule defined later in this spec.
    // Demo / Sandbox / Playground names are FORBIDDEN inside packages
    // (see `forbiddenInProductionPackages`) — they belong under
    // `src/modules/`.
    $rule(
        path: ModuleStructureSpec::TOP_LEVEL_KEY,
        allowedDirectories: [
            'Application',
            'Domain',
            'Context',
            'Configuration',
            // Canonical home for package-wide exception classes
            // (`*Exception.php` leaf files only — see `Exception` rule below).
            // Allowed in both packages and application modules. Domain-only
            // exceptions still use `Domain/Exception/` when the package
            // architecture explicitly distinguishes them.
            'Exception',
            // Package-only framework layers (see packageOnlyDirectories):
            'Attribute',
            'Auth',
            'Discovery',
            'OpenApi',
            'Pipeline',
        ],
        // `Capabilities.php` is the one source file the code root admits, and
        // it holds no code: a class carrying only `#[Capability]` declarations
        // that say what the package offers. The convention is deliberately a
        // fixed name at a fixed place — the catalog guard checks exactly this
        // class, and a declaration scattered under Application/ or Domain/
        // would be a description of the package hiding inside its
        // implementation. Named here rather than per package because it is a
        // framework-wide convention; leaving it out is what made the first five
        // packages that adopted it fail structure validation.
        allowedFiles: ['Capabilities.php'],
        rationale: 'Architectural top-level layers per Semitexa module structure §2, plus the package self-description class.',
    ),

    // ----- Application layer -----
    $rule(
        path: 'Application',
        allowedDirectories: [
            'Payload',
            'Resource',
            'Handler',
            'Service',
            'Console',
            'Update',
            'Static',
            'View',
            'Component',
            'DataProvider',
            'Db',
            'Enum',
            'Prompt',
        ],
        rationale: 'Application orchestration layer; canonical sub-trees. `Db/` hosts the persistence implementation (resource models, mappers, concrete repositories) — Domain/ stays clean of infrastructure. `Application/DataProvider` is the canonical home for **SSR DataProvider implementations** — classes that implement `Semitexa\\Ssr\\Domain\\Contract\\DataProviderInterface` and resolve display-ready data for deferred slots or `#[WithDataProvider]` components. `Application/Enum` is the canonical home for **application/runtime enums** — types that describe orchestration modes, runtime execution states, framework adapter modes, or pipeline phases. Domain enums live at `Domain/Enum/`. Top-level `src/Enum/` is intentionally NOT allowed.',
    ),
    $rule(
        path: 'Application/Payload',
        allowedDirectories: ['Request', 'Event', 'Part', 'Session'],
        rationale: 'Payload layer. `Session/` holds `#[SessionSegment]` classes and the value objects they carry — session state is payload-shaped data, not a service.',
    ),
    $rule(path: 'Application/Payload/Session', allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Payload/Request', allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Payload/Event',   allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Payload/Part',    allowFeatureGrouping: true, allowAnyFile: true),

    $rule(
        path: 'Application/Resource',
        allowedDirectories: ['Response', 'Slot'],
    ),
    $rule(path: 'Application/Resource/Response', allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Resource/Slot',     allowFeatureGrouping: true, allowAnyFile: true),

    $rule(
        path: 'Application/Handler',
        allowedDirectories: ['PayloadHandler', 'SlotHandler', 'DomainListener'],
    ),
    $rule(path: 'Application/Handler/PayloadHandler', allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Handler/SlotHandler',    allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Handler/DomainListener', allowFeatureGrouping: true, allowAnyFile: true),

    $rule(path: 'Application/Service',   allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Update',    allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Component', allowFeatureGrouping: true, allowAnyFile: true),

    // Application/Prompt — #[AsPrompt] prompt-catalog definition classes (thin:
    // metadata + optional few-shot; the Twig body lives in resources/prompts/).
    // A #[AsPrompt] class is not a service, so it gets its own home rather than
    // living under Application/Service/.
    $rule(path: 'Application/Prompt', allowFeatureGrouping: true, allowAnyFile: true),

    // Application/DataProvider — SSR DataProvider implementations.
    //
    // Canonical home for classes implementing
    // `Semitexa\Ssr\Domain\Contract\DataProviderInterface` (the deferred-slot
    // and `#[WithDataProvider]` companion providers). Feature grouping is
    // allowed (e.g. `Application/DataProvider/Articles/`); any *.php is
    // accepted because providers commonly need helper / DTO / mapper peers.
    $rule(
        path: 'Application/DataProvider',
        allowFeatureGrouping: true,
        allowAnyFile: true,
        rationale: 'SSR DataProvider implementations (DataProviderInterface). Feature grouping allowed; helper / DTO / mapper peers are accepted.',
    ),

    // Application/Enum — application/runtime enums.
    //
    // For types that describe orchestration / runtime / pipeline /
    // adapter modes (e.g. `RuntimeMode`, `DispatchMode`, `PipelinePhase`).
    // Domain enums (matching strategies, scope semantics, business
    // taxonomies) live at `Domain/Enum/` instead.
    //
    // Same shape as Domain/Enum: PascalCase concept names (no `*Enum`
    // suffix — that is NOT the codebase convention). Drift deny-list
    // rejects Helper / Util / Manager / Misc / Common / Service / Factory
    // / Provider / Adapter / Builder / Resolver basenames.
    // MODE_LEAF_FILES_ONLY: no sub-directories.
    //
    // TODO (parallel to Domain/Enum): content-aware validation could
    // require a `^enum ` declaration in each file. Filename pattern +
    // drift deny-list is the discipline for now.
    $rule(
        path: 'Application/Enum',
        mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
        allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
        excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common|Service|Services|Factory|Factories|Provider|Providers|Adapter|Adapters|Builder|Builders|Resolver|Resolvers)\.php$/'],
        rationale: 'Application/runtime enums (orchestration modes, runtime states, pipeline phases, adapter modes). PascalCase concept names; no `*Enum` suffix is required. Leaf — no subdirectories. Drift filenames (Helper / Util / Manager / Service / Factory / Provider / Adapter / Builder / Resolver) rejected.',
    ),

    // Application/Db — persistence implementation layer.
    //
    // This is the canonical home for resource models, persistence mappers, and
    // concrete database-backed repository implementations. Domain/ holds the
    // entity types and repository contracts (interfaces); Application/Db/
    // holds the storage-specific classes that implement those contracts.
    //
    // Narrow allowlist: only adapter directories named in the
    // `$persistenceAdapters` list above are permitted, and each adapter
    // carries three peer sub-trees — `Model/` (resource models),
    // `Mapper/` (persistence mappers), and `Repository/` (concrete repository
    // implementations). Anything else under Application/Db/ fails — adding
    // a new adapter (e.g. `Postgres/`) requires shipping an adapter class in
    // `semitexa-orm` AND adding it to `$persistenceAdapters` AND updating
    // MODULE_STRUCTURE.md.
    //
    // Leaf rules are tight: only the canonical filename patterns are
    // permitted. `Application/Db/<Adapter>/Model/` accepts `*Resource.php`
    // and `*ResourceModel.php` only — `*Mapper.php` belongs in the peer
    // `Mapper/` sub-tree, never in `Model/`. `SomeService.php`,
    // `SomeHelper.php`, `SomeManager.php` etc. are rejected with
    // `module_structure.invalid_location`.
    // `Application/Db/<Adapter>/Mapper/` accepts `*Mapper.php` only.
    // `Application/Db/<Adapter>/Repository/` accepts `*Repository.php` only.
    $rule(
        path: 'Application/Db',
        allowedDirectories: $persistenceAdapters,
        rationale: 'Persistence implementation. Storage-adapter sub-trees only; the list is the official semitexa-orm adapter set.',
    ),
];

// Derive per-adapter rules from the canonical list (single source of truth).
$dbModelFilePatterns = ['/(Resource|ResourceModel)\.php$/'];
$dbMapperFilePatterns = ['/Mapper\.php$/'];
$dbRepositoryFilePatterns = ['/Repository\.php$/'];

foreach ($persistenceAdapters as $adapter) {
    $codeRoot[] = $rule(
        path: 'Application/Db/' . $adapter,
        allowedDirectories: ['Model', 'Mapper', 'Repository'],
        rationale: $adapter . ' persistence adapter; three peer sub-trees: Model/ (resource models), Mapper/ (persistence mappers), Repository/ (concrete repository implementations).',
    );
    $codeRoot[] = $rule(
        path: 'Application/Db/' . $adapter . '/Model',
        allowFeatureGrouping: true,
        allowAnyFile: false,
        allowedFilePatterns: $dbModelFilePatterns,
        rationale: 'Persistence model layer for ' . $adapter . ': only *Resource and *ResourceModel class files. *Mapper.php belongs in the peer Mapper/ sub-tree.',
    );
    $codeRoot[] = $rule(
        path: 'Application/Db/' . $adapter . '/Mapper',
        allowFeatureGrouping: true,
        allowAnyFile: false,
        allowedFilePatterns: $dbMapperFilePatterns,
        rationale: 'Persistence mapper layer for ' . $adapter . ': only *Mapper class files. Resource models belong in the peer Model/ sub-tree.',
    );
    $codeRoot[] = $rule(
        path: 'Application/Db/' . $adapter . '/Repository',
        allowFeatureGrouping: true,
        allowAnyFile: false,
        allowedFilePatterns: $dbRepositoryFilePatterns,
        rationale: 'Concrete repository implementations for ' . $adapter . ': only *Repository class files.',
    );
}

// Re-open the literal list to keep diff readability for the remaining
// canonical layers below.
$codeRoot = array_merge($codeRoot, [

    // Console — the only canonical home for `#[AsCommand]` classes.
    $rule(
        path: 'Application/Console',
        allowedDirectories: ['Command'],
        rationale: 'Console commands belong to the Application layer; #[AsCommand] classes live under Application/Console/Command/.',
    ),
    // Application/Console/Command — strict file pattern: only *Command.php
    // (subject to the global file-placement rule's negative lookahead which
    // exempts PHP attribute classes named As*Command/Inject*Command).
    $rule(
        path: 'Application/Console/Command',
        allowFeatureGrouping: true,
        allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Command\.php$/'],
        rationale: 'Console command classes only; basename must end in Command.php.',
    ),

    // Static + View — assets and templates.
    $rule(
        path: 'Application/Static',
        allowedDirectories: ['css', 'js', 'img', 'fonts', 'svg', 'audio', 'vendor'],
        allowedFiles: ['assets.json'],
    ),
    $rule(path: 'Application/Static/css',   allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Static/js',    allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Static/img',   allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Static/fonts', allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Static/audio', allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/Static/svg',   allowFeatureGrouping: true, allowAnyFile: true),
    // Self-hosted third-party bundles, one directory per bundle, kept exactly as
    // their author ships them. The sibling directories above classify by asset
    // TYPE, which a bundle cannot honour: Leaflet is leaflet.js, leaflet.css and
    // images/*.png together, and its CSS references those images relative to
    // itself — split them and the vendored file's own URLs break unless you edit
    // third-party code the next upgrade overwrites. Filing the whole bundle under
    // one type directory instead is a misfiling the runtime never asked for:
    // StaticAssetHandler and ModuleAssetResolver dispatch on file extension, not
    // on the directory. A strict CSP, which this framework encourages, is what
    // produces this shape in the first place.
    $rule(
        path: 'Application/Static/vendor',
        mode: ModuleStructureRule::MODE_VENDOR_BUNDLE,
        rationale: 'Self-hosted third-party asset bundles, kept with their own internal layout intact.',
    ),

    $rule(
        path: 'Application/View',
        allowedDirectories: ['locales', 'templates'],
    ),
    $rule(path: 'Application/View/locales',   allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Application/View/templates', allowFeatureGrouping: true, allowAnyFile: true),

    // ----- Domain layer -----
    //
    // Phase 3 cleanup: `Domain/Repository` is REMOVED from the allowlist.
    // Audit (2026-04-30) found zero `Domain/Repository/` files anywhere
    // in the monorepo — all repository interfaces live in `Domain/Contract/`.
    // The phantom rule was an ambiguity for AI agents; one canonical
    // location is enough. Concrete database-specific repository
    // implementations live under `Application/Db/<Adapter>/Repository/`.
    $rule(
        path: 'Domain',
        allowedDirectories: ['Model', 'Contract', 'Exception', 'Service', 'Event', 'Command', 'Enum', 'Repository'],
        rationale: 'Domain layer. `Domain/Repository` is the canonical home for **repository port interfaces** (`*RepositoryInterface.php`); concrete implementations live in `Application/Db/<Adapter>/Repository/`. `Domain/Contract` remains the home for every other domain port. Repository ports were previously folded into `Domain/Contract` — that exclusion was lifted on 2026-07-25 because a dedicated directory keeps the persistence ports readable once a package has more than a handful. `Domain/Command` is for CQRS-style **domain command DTOs** (immutable message objects describing an intent) — it is NOT for executable console commands; those live exclusively at `Application/Console/Command/`. `Domain/Enum` is the canonical home for **domain enums** — types that describe business/domain semantics (matching strategies, field-type taxonomies, scope semantics, status taxonomies). Application/runtime enums live at `Application/Enum/`. Top-level `src/Enum/` is intentionally NOT allowed — enums must be placed contextually.',
    ),
    // Domain/Model — entities only. The excludedFilePatterns deny-list
    // rejects persistence-implementation filenames (*Resource.php,
    // *ResourceModel.php, *Mapper.php) — those belong under
    // Application/Db/<Adapter>/Model/, not in the domain layer.
    $rule(
        path: 'Domain/Model',
        allowFeatureGrouping: true,
        allowAnyFile: true,
        excludedFilePatterns: ['/(Resource|ResourceModel|Mapper)\.php$/'],
        rationale: 'Domain entities only. Persistence resource models and mappers belong under Application/Db/<Adapter>/Model/.',
    ),
    // Domain/Contract — the canonical (and only) home for domain
    // interfaces, including repository interfaces. Pattern enforces the
    // *Interface.php naming convention.
    $rule(
        path: 'Domain/Contract',
        allowFeatureGrouping: true,
        allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Interface\.php$/'],
        rationale: 'Home for domain interfaces other than repository ports. Basename ends in Interface.php. Repository ports live in Domain/Repository/; concrete repository implementations live in Application/Db/<Adapter>/Repository/.',
    ),
    // Domain/Repository — repository PORT interfaces only. The concrete
    // implementations stay in Application/Db/<Adapter>/Repository/, so this
    // layer never holds infrastructure.
    $rule(
        path: 'Domain/Repository',
        allowFeatureGrouping: true,
        allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*RepositoryInterface\.php$/'],
        rationale: 'Repository port interfaces. *RepositoryInterface.php only — a concrete class here would be infrastructure leaking into the domain layer.',
    ),
    // Domain/Exception — exception classes (basename ends in Exception.php).
    $rule(
        path: 'Domain/Exception',
        allowFeatureGrouping: true,
        allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Exception\.php$/'],
        rationale: 'Domain exception classes only (basename ends in Exception.php).',
    ),
    $rule(path: 'Domain/Service',    allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Domain/Event',      allowFeatureGrouping: true, allowAnyFile: true),
    // Domain/Command — CQRS-style **domain command DTOs**.
    //
    // These are immutable `final readonly class` message objects whose
    // basenames legitimately end in `Command.php` (e.g. `StartWorkflowCommand`,
    // `ApplyTransitionCommand`). They are NOT console commands. The placement
    // rule for executable console commands (`'command'` in $filePlacement)
    // declares `Domain/Command` as exempt, so the same basename pattern does
    // not double-trigger `module_structure.command_wrong_location` for files
    // here.
    //
    // Strict allowlist: only `*Command.php` PascalCase basenames are accepted.
    // Anything else (a misplaced handler, a service class, etc.) falls through
    // and fails — this keeps `Domain/Command` honest as a CQRS DTO bucket.
    // Domain/Enum — domain-semantic enums.
    //
    // Naming: PascalCase concept names (no `*Enum` suffix — that is NOT
    // the codebase convention; e.g. `SearchScope`, `MediaKind`,
    // `WebhookDirection`, `HttpStatus`). The `*Enum` regex would force a
    // public-API rename across every existing enum and is rejected.
    //
    // Strict drift deny-list rejects Helper / Util / Manager / Misc /
    // Common / Service / Factory / Provider / Adapter / Builder / Resolver
    // basenames — none of those are enums. MODE_LEAF_FILES_ONLY: no
    // sub-directories.
    //
    // TODO: content-aware validation — a future enhancement could open the
    // file and require a `^enum ` declaration to close the gap between
    // "PascalCase non-drift filename" and "actually a PHP enum class". For
    // now the filename pattern + drift deny-list is the discipline; AI
    // agents that try to slip a non-enum class into Domain/Enum are caught
    // by code review. Track via the `Domain/Enum` audit when the surface
    // grows.
    $rule(
        path: 'Domain/Enum',
        mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
        allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
        excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common|Service|Services|Factory|Factories|Provider|Providers|Adapter|Adapters|Builder|Builders|Resolver|Resolvers)\.php$/'],
        rationale: 'Domain-semantic enums (matching strategies, scope semantics, field-type taxonomies, status taxonomies). PascalCase concept names; no `*Enum` suffix is required. Leaf — no subdirectories. Drift filenames (Helper / Util / Manager / Service / Factory / Provider / Adapter / Builder / Resolver) rejected.',
    ),
    $rule(
        path: 'Domain/Command',
        allowFeatureGrouping: true,
        allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Command\.php$/'],
        rationale: 'CQRS-style domain command DTOs (final readonly classes, basename ends in Command.php). NOT for executable console commands — those live at Application/Console/Command/. The console-command file-placement rule treats this path as exempt.',
    ),

    // ----- Other canonical top-level layers -----
    $rule(path: 'Context',       allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Configuration', allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Update',        allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'Static',        allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'View',          allowFeatureGrouping: true, allowAnyFile: true),

    // ----- Package-only framework layers -----
    // Listed in `packageOnlyDirectories` below so they trigger
    // `module_structure.invalid_layer` inside `src/modules/*`.
    // Each is a recurring named pattern across the Semitexa packages
    // (semitexa-core, semitexa-auth, semitexa-api, semitexa-graphql, …).
    // Attribute (singular) — PHP attribute classes only. Permits any
    // PascalCase class file (As*.php, Inject*.php, Satisfies*.php,
    // *Attribute.php, plus single-word names like Config.php). Lowercase
    // files, dotfiles, and non-class artifacts fall through and fail.
    //
    // Excludes *Command.php basenames that are NOT obvious attribute
    // classes (As*Command, Inject*Command). A file like
    // `RealBusinessCommand.php` inside Attribute/ looks like a misplaced
    // console command — reject it so the file-placement rule's intent is
    // not bypassed by the broad PascalCase allowance here.
    $rule(
        path: 'Attribute',
        allowFeatureGrouping: true,
        allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
        excludedFilePatterns: ['/^(?!(As|Inject))[A-Z][A-Za-z0-9_]*Command\.php$/'],
        rationale: 'PHP attribute classes (#[ExternalApi], #[ApiVersion], #[InjectAsReadonly], etc.); universal package convention. Strict allowlist for PascalCase class files; *Command.php basenames other than the canonical attribute names As*/Inject* are rejected (they read as misplaced console commands).',
    ),
    $rule(
        path: 'Auth',
        allowFeatureGrouping: true,
        allowAnyFile: true,
        rationale: 'Auth integration / principal types / handlers — recurring framework concept.',
    ),
    $rule(
        path: 'Discovery',
        allowFeatureGrouping: true,
        allowAnyFile: true,
        rationale: 'Discovery adapters that implement Core discovery contracts.',
    ),
    // OpenApi — narrow framework sub-system.
    //
    // CRITICAL: `OpenApi/Attribute/` is intentionally NOT in the allowed
    // children. PHP attribute declarations canonically live in the
    // package's top-level `Attribute/` (singular) directory — there is
    // exactly one home for attribute classes per package. An audit on
    // 2026-04-30 found `Semitexa\Api\OpenApi\Attribute` as a duplicate
    // attribute namespace alongside `Semitexa\Api\Attribute`; it was
    // collapsed into `src/Attribute/` so AI agents and humans have one
    // unambiguous answer to "where do I put a new package PHP attribute?".
    //
    // OpenApi/ remains for OpenAPI infrastructure: schema generators,
    // route metadata generators, and the OpenApi*.php document builder.
    // Adding `Attribute` back here re-introduces the ambiguity and is
    // forbidden.
    $rule(
        path: 'OpenApi',
        allowedDirectories: ['Schema', 'Route'],
        allowedFilePatterns: ['/^OpenApi[A-Z][A-Za-z0-9]*\.php$/'],
        rationale: 'OpenAPI document generation facility. Children: only Schema/ + Route/ (no Attribute/ — see Attribute canonical location). Root files: OpenApi*.php builder/orchestrator only.',
    ),
    $rule(path: 'OpenApi/Schema',    allowFeatureGrouping: true, allowAnyFile: true),
    $rule(path: 'OpenApi/Route',     allowFeatureGrouping: true, allowAnyFile: true),
    $rule(
        path: 'Pipeline',
        allowFeatureGrouping: true,
        allowAnyFile: true,
        rationale: 'Pipeline middleware / mappers / decorators; recurring framework concept.',
    ),
]);

// ----------------------------------------------------------------------
// Phase 2: per-package leaf rules MUST exist explicitly. Silent skipping
// is forbidden; either declare deep_validated children, or mark the path
// opaque_internal with a documented reason.
// ----------------------------------------------------------------------

// `Container/` (semitexa-core) — deep_validated. The DI container has a
// known internal shape: a build-phase namespace, exception types, and a
// store namespace. Anything else (Helpers/, Misc/, …) fails.
$codeRoot[] = $rule(
    path: 'Container',
    allowedDirectories: ['BuildPhase', 'Exception', 'Store'],
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    allowedFiles: ['README.md'],
    rationale: 'semitexa-core DI container: BuildPhase/, Exception/, Store/ sub-trees plus PSR-4 class files at the root.',
);
$codeRoot[] = $rule(
    path: 'Container/BuildPhase',
    mode: ModuleStructureRule::MODE_OPAQUE_INTERNAL,
    opaqueReason: 'Container build phases are framework runtime internals; deep child rules pending Phase 3.',
    opaqueOwner: 'semitexa-core team',
    opaqueTodo: 'Enumerate the build-phase classes and add explicit allowedFilePatterns when the surface stabilises.',
);
$codeRoot[] = $rule(
    path: 'Container/Exception',
    allowFeatureGrouping: true,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Exception\.php$/'],
    rationale: 'Container exception classes only (basename ends in Exception.php).',
);
$codeRoot[] = $rule(
    path: 'Container/Store',
    mode: ModuleStructureRule::MODE_OPAQUE_INTERNAL,
    opaqueReason: 'Container store types are framework runtime internals; deep child rules pending Phase 3.',
    opaqueOwner: 'semitexa-core team',
    opaqueTodo: 'Enumerate store classes and add explicit allowedFilePatterns when the surface stabilises.',
);

// `Console/` (top-level, semitexa-core) — deep_validated. Framework
// console infrastructure ONLY. Children: console `Application` class
// (the Symfony console kernel), the abstract `BaseCommand` that every
// Semitexa command extends, and `Runtime/`. **`Console/Command/` is NOT
// allowed here** (Phase 8): executable console commands universally live
// under `Application/Console/Command/`. Keeping a `Console/Command/`
// exception in semitexa-core would create a dual canonical location and
// mislead AI agents into placing new commands there.
$codeRoot[] = $rule(
    path: 'Console',
    allowedDirectories: ['Runtime'],
    allowedFiles: ['Application.php', 'BaseCommand.php'],
    rationale: 'semitexa-core framework console infrastructure: Application (Symfony console kernel), BaseCommand (abstract base), Runtime/ subtree. **Executable commands live under Application/Console/Command/, never here.**',
);
$codeRoot[] = $rule(
    path: 'Console/Runtime',
    mode: ModuleStructureRule::MODE_OPAQUE_INTERNAL,
    opaqueReason: 'Console runtime is framework infrastructure; deep child rules pending future hardening.',
    opaqueOwner: 'semitexa-core team',
    opaqueTodo: 'Enumerate runtime classes and add explicit rules when the surface stabilises.',
);

// `Boot/` (semitexa-core) — framework bootstrapping helpers that wire
// wildcard module autoloading before the application kernel starts.
// Leaf-only: root helper classes such as LocalModuleAutoloadRegistrar.php.
$codeRoot[] = $rule(
    path: 'Boot',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'semitexa-core bootstrapping helpers that prepare framework/runtime wiring before the kernel fully boots. Leaf — PascalCase helper classes only.',
);

// ----------------------------------------------------------------------
// Phase 3 batch 1 (2026-04-30): converted from opaque_internal to
// deep_validated. Each rule below pins the actual semitexa-core layout
// found at audit time (`packages/semitexa-core/src/<DIR>/`) with narrow,
// named child rules. Adding new files inside these layers requires
// matching the file-name pattern; adding a new sub-tree requires editing
// the rule.
// ----------------------------------------------------------------------

// Exception — canonical home for package-wide exception classes.
//
// Allowed at the top-level of both packages and application modules.
// MODE_LEAF_FILES_ONLY: no sub-directories. The `*Exception.php` basename
// pattern is the only filter — files like `ExceptionFactory.php`,
// `SomeService.php`, `SomeHelper.php`, or `ErrorHandler.php` do not match
// and are rejected (`module_structure.invalid_location`). Sub-directories
// fail with `module_structure.unknown_directory` because the rule is
// leaf-only.
//
// `Domain/Exception/` is the alternative location for exceptions whose
// scope is explicitly limited to the domain layer of the package (a
// distinct DDD concept of domain invariant). Most packages do NOT need
// that distinction — package-wide exceptions belong here at top-level
// `Exception/`.
$codeRoot[] = $rule(
    path: 'Exception',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Exception\.php$/'],
    rationale: 'Canonical home for package-wide exception classes. Leaf — no subdirectories. Basenames must end in Exception.php (so `ExceptionFactory.php` etc. are rejected). Allowed in both packages and application modules.',
);

// Event — file-only leaf; PascalCase class files. Drift deny-list added
// in Phase 4 tightening.
$codeRoot[] = $rule(
    path: 'Event',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Framework event system internals (EventDispatcher, EventListenerRegistry, etc.). Leaf — no subdirectories. Drift filenames rejected.',
);

// Config — file-only leaf. Drift deny-list added in Phase 4 tightening.
$codeRoot[] = $rule(
    path: 'Config',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Framework configuration types (EnvValueResolver, RegistryConfig). Leaf — no subdirectories. Drift filenames rejected.',
);

// Redis — file-only leaf with adapter-name prefix pattern.
$codeRoot[] = $rule(
    path: 'Redis',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^Redis[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Redis adapter classes (basename starts with Redis). Leaf — no subdirectories.',
);

// Support — file-only leaf with PascalCase utility classes. The
// excludedFilePatterns deny-list locks Support against the recurring AI
// drift pattern of using it as a general dumping ground (Helpers/, Utils/,
// Managers/, Misc/, Common/ all forbidden by name). New utility classes
// must have a concrete, single-purpose name (CodeExporter, ProjectRoot,
// Str, etc.); they must NOT end in Helper/Util/Manager/Misc/Common.
$codeRoot[] = $rule(
    path: 'Support',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Generic supporting utility classes (CodeExporter, ProjectRoot, Str, …). Leaf — no subdirectories. Helper/Util/Manager/Misc/Common-suffixed filenames are explicitly rejected to stop Support from becoming a dumping ground.',
);

// Validation — PascalCase value-object/support classes at root +
// traits under Trait/. Files at root must NOT end in Trait.php (those
// live under Trait/) and must NOT carry Helper/Util/Manager/Misc/Common
// suffixes that erode the namespace into a dumping ground.
$codeRoot[] = $rule(
    path: 'Validation',
    allowedDirectories: ['Trait'],
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Trait|Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Framework validation primitives. PascalCase root-level value objects (Path, …); traits under Trait/.',
);
$codeRoot[] = $rule(
    path: 'Validation/Trait',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Trait\.php$/'],
    rationale: 'Validation trait classes (basename ends in Trait.php). Leaf — no subdirectories.',
);

// Http — files at root + Exception/ + Response/ subdirectories.
$codeRoot[] = $rule(
    path: 'Http',
    allowedDirectories: ['Exception', 'Response'],
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'HTTP wrappers (semitexa-core). PascalCase class files at root + Exception/ + Response/ sub-trees.',
);
$codeRoot[] = $rule(
    path: 'Http/Exception',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Exception\.php$/'],
    rationale: 'HTTP exception types. Leaf.',
);
$codeRoot[] = $rule(
    path: 'Http/Response',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'HTTP response types. Leaf.',
);

// Queue — files at root + Message/ + Transport/ subdirectories.
$codeRoot[] = $rule(
    path: 'Queue',
    allowedDirectories: ['Message', 'Transport'],
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Queue primitives (QueueDispatcher, QueueWorker, etc.) plus Message/ + Transport/ sub-trees.',
);
$codeRoot[] = $rule(
    path: 'Queue/Message',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Message\.php$/'],
    rationale: 'Queue message classes (basename ends in Message.php). Leaf.',
);
$codeRoot[] = $rule(
    path: 'Queue/Transport',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Transport(?:Factory)?\.php$/'],
    rationale: 'Queue transport adapters (Transport / TransportFactory naming). Leaf.',
);

// ----------------------------------------------------------------------
// Phase 3 batch 2 (2026-04-30): runtime / security / request-lifecycle
// internals promoted from opaque_internal to deep_validated /
// leaf_files_only. Same discipline as batch 1: every rule pins the
// actual semitexa-core layout found at audit time, with narrow
// allowed-children sets and pattern-driven file allowlists.
// ----------------------------------------------------------------------

// Authorization — leaf, currently 1 file (SubjectInterface).
// Drift deny-list (Helper/Util/Manager/Misc/Common) prevents the layer
// from becoming a dumping ground for unrelated code.
$codeRoot[] = $rule(
    path: 'Authorization',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Authorization primitives (SubjectInterface, …). Leaf — no subdirectories. Drift filenames rejected.',
);

// Cookie — leaf with `Cookie*` prefix (CookieJarInterface, CookieJar).
$codeRoot[] = $rule(
    path: 'Cookie',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^Cookie[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Cookie types (CookieJar, CookieJarInterface). Leaf — basenames must start with Cookie.',
);

// Csrf — deep with Attribute/ child + `Csrf*`-prefixed root files
// (CsrfListener, CsrfToken).
$codeRoot[] = $rule(
    path: 'Csrf',
    allowedDirectories: ['Attribute'],
    allowedFilePatterns: ['/^Csrf[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'CSRF subsystem: Attribute/ for #[CsrfExempt]-style attributes, plus Csrf*-prefixed root files (CsrfListener, CsrfToken).',
);
$codeRoot[] = $rule(
    path: 'Csrf/Attribute',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'PHP attribute classes for the Csrf subsystem. Leaf.',
);

// Request — deep with only Attribute/ child; no root files allowed.
$codeRoot[] = $rule(
    path: 'Request',
    allowedDirectories: ['Attribute'],
    rationale: 'Framework Request types. Currently only Attribute/ child — root files (the request entry-points) live one level up at semitexa-core/src/Request*.php.',
);
$codeRoot[] = $rule(
    path: 'Request/Attribute',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'PHP attribute classes for the Request subsystem (PathParam, …). Leaf.',
);

// Session — deep with Attribute/ child + many PascalCase root files.
$codeRoot[] = $rule(
    path: 'Session',
    allowedDirectories: ['Attribute'],
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Framework session subsystem: Attribute/ + PascalCase root files (Session, SessionInterface, RedisSessionHandler, SwooleTableSessionHandler, …).',
);
$codeRoot[] = $rule(
    path: 'Session/Attribute',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'PHP attribute classes for the Session subsystem (SessionSegment, …). Leaf.',
);

// Server — deep with Lifecycle/ child + PascalCase root files.
$codeRoot[] = $rule(
    path: 'Server',
    allowedDirectories: ['Lifecycle'],
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Server runtime: Lifecycle/ for server-lifecycle phase classes, plus PascalCase root files (CorsHandler, MetricsHandler, SwooleBootstrap, …).',
);
$codeRoot[] = $rule(
    path: 'Server/Lifecycle',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Worker lifecycle subsystem: Server*-prefixed phases/context/invoker/registry PLUS core-owned lifecycle listeners and their collaborators (ClearWorkerTimersListener, WorkerTimerRegistry — the WorkerExit drain). PascalCase leaf with the standard drift deny-list; matches semitexa-core\'s local extension so the contract is identical with or without it.',
);

// Resource — deep with 9 declared sub-trees + many PascalCase root files.
// This is the largest semitexa-core layer; the sub-tree list pins the
// known shape so any new sibling fails until explicitly declared.
$codeRoot[] = $rule(
    path: 'Resource',
    allowedDirectories: [
        'Attribute',
        'Cursor',
        'Exception',
        'Filter',
        'Lifecycle',
        'Memo',
        'Metadata',
        'Pagination',
        'Sort',
    ],
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Resource type system. 9 declared sub-trees (Attribute / Cursor / Exception / Filter / Lifecycle / Memo / Metadata / Pagination / Sort) plus PascalCase root files (resource renderers, identity, includes, …).',
);
$codeRoot[] = $rule(
    path: 'Resource/Attribute',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'PHP attribute classes for resources (ResourceObject, ResourceField, ResourceListOf, …). Leaf.',
);
$codeRoot[] = $rule(
    path: 'Resource/Cursor',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Cursor[A-Za-z0-9_]*\.php$/'],
    rationale: 'Cursor pagination types (CollectionCursor, CollectionCursorCodec, CollectionCursorPage). Leaf — basenames contain Cursor.',
);
$codeRoot[] = $rule(
    path: 'Resource/Exception',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Exception\.php$/'],
    rationale: 'Resource subsystem exceptions. Leaf — basenames end in Exception.php.',
);
$codeRoot[] = $rule(
    path: 'Resource/Filter',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Collection filter types (CollectionFilterRequest, FilterOperator, FilterTerm). Leaf.',
);
$codeRoot[] = $rule(
    path: 'Resource/Lifecycle',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Listener\.php$/'],
    rationale: 'Resource lifecycle listeners (WarmResourceMetadataListener, …). Leaf — basenames end in Listener.php.',
);
$codeRoot[] = $rule(
    path: 'Resource/Memo',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Store\.php$/'],
    rationale: 'Resolver memoisation stores (ResolverMemoStore). Leaf — basenames end in Store.php.',
);
$codeRoot[] = $rule(
    path: 'Resource/Metadata',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Resource metadata types (ResourceMetadataExtractor, ResourceMetadataRegistry, ResourceMetadataValidator, …). Leaf.',
);
$codeRoot[] = $rule(
    path: 'Resource/Pagination',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^Collection[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Pagination types (CollectionPage, CollectionPageRequest). Leaf — basenames start with Collection.',
);
$codeRoot[] = $rule(
    path: 'Resource/Sort',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Sort types (CollectionSortRequest, SortDirection, SortTerm). Leaf.',
);

// ----------------------------------------------------------------------
// Phase 3 batch 3 (2026-04-30): tooling / framework-internal areas
// promoted from opaque_internal. Same discipline as previous batches:
// each rule pins the actual semitexa-core layout with narrow allowed-
// children + pattern-driven file allowlists + drift deny-lists.
// ----------------------------------------------------------------------

// CodeGen — leaf with the *Generator suffix. Currently only LayoutGenerator;
// new generator classes must keep the suffix so the layer cannot become
// a generic dumping ground for arbitrary generated code.
$codeRoot[] = $rule(
    path: 'CodeGen',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Generator\.php$/'],
    rationale: 'Code-generation infrastructure (LayoutGenerator, …). Leaf — basenames must end in Generator.php so the layer stays narrowly about generators.',
);

// Composer — leaf for the Composer plugin. Strictly *Plugin classes.
$codeRoot[] = $rule(
    path: 'Composer',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Plugin\.php$/'],
    rationale: 'Composer plugin classes (SemitexaPlugin, …). Leaf — basenames end in Plugin.php; this layer is exclusively for Composer integration.',
);

// PHPStan — DEEP with only Rules/ child; no root files allowed (PHPStan
// extension wires up via `Rules/` only at this point).
$codeRoot[] = $rule(
    path: 'PHPStan',
    allowedDirectories: ['Rules'],
    rationale: 'PHPStan rule sources. Currently only Rules/; root files are not used. Excluded from autoload classmap.',
);
$codeRoot[] = $rule(
    path: 'PHPStan/Rules',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Rule\.php$/'],
    rationale: 'PHPStan custom rule classes; basename must end in Rule.php. Leaf — no subdirectories.',
);

// Lifecycle — leaf with mixed PascalCase suffixes (*Phase / *Registry /
// *Context). Drift deny-list prevents Helper/Util/Manager/Misc/Common
// drift.
$codeRoot[] = $rule(
    path: 'Lifecycle',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Server / request lifecycle phases & contexts (LifecycleComponentRegistry, LocalePhase, RoutePhase, SessionPhase, TenancyPhase, RequestLifecycleContext). Leaf — drift filenames rejected.',
);

// Registry — leaf. Currently 2 files (CanonicalRegistryPaths,
// RegistryContractResolverGenerator). Drift deny-list as above.
$codeRoot[] = $rule(
    path: 'Registry',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Registry pattern types (CanonicalRegistryPaths, RegistryContractResolverGenerator). Leaf — drift filenames rejected.',
);

// Log — leaf with mixed naming (*Logger, LoggerInterface, LogLevel,
// *Bridge). Drift deny-list as above.
$codeRoot[] = $rule(
    path: 'Log',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Logging primitives (AsyncJsonLogger, FallbackErrorLogger, LoggerInterface, LogLevel, StaticLoggerBridge). Leaf — drift filenames rejected.',
);

// Error — leaf with `Error*` prefix. All 4 current files (ErrorDispatchState,
// ErrorPageContext, ErrorPageContextStore, ErrorRouteDispatcher) start
// with Error.
$codeRoot[] = $rule(
    path: 'Error',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^Error[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Error handling types (ErrorDispatchState, ErrorPageContext, ErrorPageContextStore, ErrorRouteDispatcher). Leaf — basenames must start with Error so the layer stays narrowly about error handling.',
);

// ----------------------------------------------------------------------
// Phase 4 (2026-04-30): the final 4 opaque directories promoted.
// `$coreOpaqueDirs` is now empty — every core-only directory has an
// explicit deep_validated / leaf_files_only rule. The `opaque_internal`
// MODE remains in the spec as an explicit opt-out for future architectural
// areas where deep child rules cannot be authored at first sight, but no
// directory currently uses it.
// ----------------------------------------------------------------------

// Contract (framework-level) — leaf for framework-wide interfaces.
// **NOT** the same as Domain/Contract: this lives at semitexa-core/src/Contract
// and holds framework-level contracts (TypedHandlerInterface,
// ExceptionResponseMapperInterface, RouteMetadataResolverInterface, etc.)
// implemented by the framework runtime. Domain/Contract is the canonical
// home for module/domain-level interfaces (including repository
// interfaces). See MODULE_STRUCTURE.md "Contract vs Domain/Contract" for
// the distinction. Every file here must end in `Interface.php`.
$codeRoot[] = $rule(
    path: 'Contract',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Interface\.php$/'],
    rationale: 'Framework-wide contracts (TypedHandlerInterface, ExceptionResponseMapperInterface, …). Leaf — *Interface.php basenames only. NOT the same layer as Domain/Contract (module-level interfaces); see MODULE_STRUCTURE.md.',
);

// Locale — leaf for locale context types. Currently 2 files
// (DefaultLocaleContext, LocaleContextInterface). PascalCase + drift
// deny-list keeps the layer narrow without enumerating every name.
$codeRoot[] = $rule(
    path: 'Locale',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Framework-level locale context (DefaultLocaleContext, LocaleContextInterface). Leaf — no subdirectories. Drift filenames rejected.',
);

// Tenant — DEEP. Root holds PascalCase context/resolver/factory
// interfaces (TenantContextInterface, TenantResolverInterface, …) plus
// access types (TenantContextAccess) and bootstrapper interfaces. Two
// children: Layer/ (composable tenant layers) and Scope/ (tenant scope
// types). Each child gets its own narrow file pattern. Drift deny-list
// at root.
$codeRoot[] = $rule(
    path: 'Tenant',
    allowedDirectories: ['Layer', 'Scope'],
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*\.php$/'],
    excludedFilePatterns: ['/(Helper|Helpers|Util|Utils|Manager|Managers|Misc|Common)\.php$/'],
    rationale: 'Framework tenant subsystem: Layer/ (composable tenant layers like Environment/Locale/Organization/Theme) and Scope/ (tenant scope types) sub-trees, plus PascalCase root files (context interfaces, resolvers, bootstrapper interfaces). Drift filenames rejected at root.',
);
$codeRoot[] = $rule(
    path: 'Tenant/Layer',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*(Layer|Value|Interface)\.php$/'],
    rationale: 'Tenant layer + value classes (EnvironmentLayer, EnvironmentValue, LocaleLayer, LocaleValue, OrganizationLayer, OrganizationValue, ThemeLayer, TenantLayerInterface, TenantLayerValueInterface). Leaf — basenames must end in Layer.php / Value.php / Interface.php.',
);
$codeRoot[] = $rule(
    path: 'Tenant/Scope',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^[A-Z][A-Za-z0-9_]*Scope[A-Za-z0-9_]*\.php$/'],
    rationale: 'Tenant scope types (NullTenantScope, TenantScopeInterface). Leaf — basenames must contain Scope.',
);

// Theme — leaf, currently 1 file (ThemeProviderInterface). `Theme*`
// prefix keeps the layer narrowly about Theme-related contracts. If
// Theme grows into a larger sub-system later, the rule can be promoted
// to DEEP_VALIDATED with explicit children.
$codeRoot[] = $rule(
    path: 'Theme',
    mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
    allowedFilePatterns: ['/^Theme[A-Z][A-Za-z0-9_]*\.php$/'],
    rationale: 'Framework Theme integration types (ThemeProviderInterface, …). Leaf — basenames must start with Theme so the layer cannot become a generic frontend dumping ground. Promote to DEEP_VALIDATED with explicit children if/when sub-tree grows.',
);

// The `opaque_internal` mode remains in use for a few framework-runtime
// sub-trees in semitexa-core (Container/, Console/Runtime) until their
// child classes stabilise and explicit allowlists land in Phase 3/4.
$coreOpaqueDirs = [];
foreach ($coreOpaqueDirs as $dir => $purpose) {
    $codeRoot[] = $rule(
        path: $dir,
        mode: ModuleStructureRule::MODE_OPAQUE_INTERNAL,
        opaqueReason: $purpose . ' Deep child rules pending Phase 3+ — surfaces are still evolving.',
        opaqueOwner: $coreOpaqueOwner,
        opaqueTodo: $coreOpaqueTodo,
    );
}

// Indexed by path for the validator's O(1) lookup.
$codeRootMap = [];
foreach ($codeRoot as $r) {
    if (isset($codeRootMap[$r->path])) {
        throw new \LogicException("Duplicate module structure rule path: '{$r->path}'");
    }
    $codeRootMap[$r->path] = $r;
}

// Package envelope — what `packages/semitexa-{name}/` itself may contain.
// Application modules at `src/modules/{Name}/` use the codeRoot rules
// directly; their root IS the code root.
$packageRoot = new ModuleStructureRule(
    path: 'package_root',
    allowedDirectories: [
        'src',
        'tests',
        'docs',
        'bin',
        'resources',
        'tools',
        'public',
        'var',
        // Package-owned bootstraps (for example phpunit bootstraps) live
        // here. This is envelope-only: package `src/` still stays under the
        // strict code-root allowlist.
        'bootstrap',
        '.github',
        '.git',
        '.phpunit.cache',
        'commands',
        'scaffold',
        // `config/` is the canonical envelope slot for package-local
        // module-structure extensions (`config/module-structure.php`).
        // Loader: ModuleStructureSpecLoader merges any extension found
        // there. NOT a wildcard for arbitrary package config — the only
        // file the validator currently consumes here is module-structure.php.
        'config',
    ],
    allowedFiles: [
        'composer.json',
        'composer.lock',
        '.gitignore',
        '.env.default',
        '.phpunit.result.cache',
        'LICENSE',
        'Dockerfile',
        'phpstan.neon',
        'phpstan-baseline.neon',
        'theme.json',
        'server.php',
    ],
    allowedFilePatterns: [
        '/^README(\.md)?$/',
        '/^CHANGELOG(\.md)?$/',
        '/^AGENTS(\.md)?$/',
        '/^AGENTS_DOCTRINE\.md$/',
        '/^AI_(CONTEXT|ENTRY|NOTES|REFERENCE)\.md$/',
        '/^CLAUDE\.md$/',
        '/^phpunit\.xml(\.dist)?$/',
        '/^docker-compose(\.[a-z0-9_-]+)?\.ya?ml$/',
        '/^[a-z0-9_-]+\.sh$/',
    ],
    rationale: 'Package envelope: composer metadata, source, tests, docs, tooling.',
);

// File-placement rules: a file whose basename matches `pattern` MUST live at
// `requiredPath` (or a feature-grouping subfolder thereof). These rules
// override the per-directory `allowAnyFile` flag for matching files.
$filePlacement = [
    // *Command.php → must live under Application/Console/Command/.
    //
    // The pattern uses a negative lookahead to skip Semitexa attribute
    // classes whose basenames also end in `Command.php` by convention —
    // `AsCommand.php`, `InjectAsCommand.php`, etc. Those live under
    // `Attribute/` and are not console commands. Real console commands
    // (`CacheClearCommand`, `DumpOpenApiCommand`, etc.) never start with
    // `As`/`Inject` because that prefix is reserved for PHP attribute
    // classes throughout the Semitexa codebase.
    //
    // Exempt: `Domain/Command/` holds CQRS-style **domain command DTOs**
    // (final readonly classes describing an intent, not Symfony console
    // commands). Their basenames legitimately end in `Command.php` but
    // they belong in the domain layer; this placement rule defers to the
    // `Domain/Command` per-directory rule for those files.
    //
    // The `forbiddenContentPatternsUnderExempt` patterns close the
    // loophole: if a file under `Domain/Command/` actually behaves like an
    // executable console command (carries `#[AsCommand]`, extends Semitexa's
    // `BaseCommand`, or extends/imports Symfony's `Symfony\Component\Console\Command\Command`
    // base class), the exemption is REVOKED and `command_wrong_location`
    // fires anyway. The path-only check would otherwise let an executable
    // command hide in the domain layer.
    'command' => new FilePlacementRule(
        code:        'module_structure.command_wrong_location',
        pattern:     '/^(?!(As|Inject))[A-Z][A-Za-z0-9_]*Command\.php$/',
        requiredPath: 'Application/Console/Command',
        description: 'console command (#[AsCommand] class)',
        exemptUnderPaths: ['Domain/Command'],
        forbiddenContentPatternsUnderExempt: [
            // Semitexa #[AsCommand] attribute (with optional FQCN prefix);
            // matches `#[AsCommand]`, `#[AsCommand(`, `#[AsCommand(...)`,
            // and `#[Semitexa\Core\Attribute\AsCommand(...)`. The negative
            // lookahead on the basename pattern already excludes
            // `AsCommand.php` itself (the attribute class definition).
            '/#\[\s*(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?AsCommand\b/',
            // Extends Semitexa\Core\Console\BaseCommand (the abstract base
            // for every Semitexa executable command), bare or fully
            // qualified.
            '/\bextends\s+(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?BaseCommand\b/',
            // References Symfony\Component\Console\Command\Command anywhere
            // (use statement, extends, instanceof, ::class, …). Domain
            // command DTOs have no business knowing about the Symfony
            // console kernel; presence of this FQCN means the file is — or
            // is wired into — an executable console command.
            '/Symfony\\\\Component\\\\Console\\\\Command\\\\Command\b/',
        ],
    ),
];

/**
 * Per-package additional allowlists for the package's source root
 * (`packages/semitexa-NAME/src/`).
 *
 * Each entry is narrow, named, and explicit — not a wildcard. The map key
 * is the package's short name (e.g. `core` for `packages/semitexa-core`).
 *
 * Currently only `semitexa-core` carries entries here, because it is the
 * framework runtime and legitimately holds infrastructure layers that no
 * other package should host:
 *
 *   - `Container`, `Composer`, `Lifecycle`, `Console` (top-level), `PHPStan`
 *     are core-only by architecture (the DI container, the Composer plugin,
 *     server lifecycle, framework console infrastructure, and the project's
 *     PHPStan rule sources).
 *   - `Authorization`, `CodeGen`, `Config`, `Contract`, `Cookie`,
 *     `Csrf`, `Error`, `Event`, `Http`, `Locale`, `Log`, `Queue`, `Redis`,
 *     `Registry`, `Request`, `Resource`, `Server`, `Session`, `Support`,
 *     `Tenant`, `Theme`, `Validation` are framework primitives owned by
 *     Core; application packages consume them through contracts but never
 *     host them.
 *   - `Exception` is **not** core-only — it is the canonical home for
 *     package-wide exception classes everywhere (see top-level
 *     `allowedDirectories`). Listed here historically; removed from this
 *     map now that the rule is global.
 *   - The handful of entry-point class files at `semitexa-core/src/` root
 *     (`Application.php`, `Environment.php`, `ErrorHandler.php`,
 *     `HttpResponse.php`, `ModuleRegistry.php`, `Request.php`,
 *     `RequestFactory.php`) are the framework's PSR-4 entry points and live
 *     directly under the source root by design.
 *
 * Adding a new core-only directory or file = edit this map AND
 * MODULE_STRUCTURE.md in lockstep. Do NOT use this map to whitelist
 * existing drift in other packages — drift gets refactored, not approved.
 */
$packageSpecificCodeRoot = [
    'core' => [
        'directories' => [
            'Authorization',
            'Boot',
            'CodeGen',
            'Composer',
            'Config',
            'Console',
            'Container',
            'Contract',
            'Cookie',
            'Csrf',
            'Error',
            'Event',
            'Http',
            'Lifecycle',
            'Locale',
            'Log',
            'PHPStan',
            'Queue',
            'Redis',
            'Registry',
            'Request',
            'Resource',
            'Server',
            'Session',
            'Support',
            'Tenant',
            'Theme',
            'Validation',
        ],
        'files' => [
            'Application.php',
            'Environment.php',
            'ErrorHandler.php',
            'HttpResponse.php',
            'ModuleRegistry.php',
            'Request.php',
            'RequestFactory.php',
        ],
    ],
];

return new ModuleStructureSpec(
    codeRootRules: $codeRootMap,
    packageRootRule: $packageRoot,
    filePlacement: $filePlacement,
    // Package-only framework layers — valid at `packages/semitexa-*/src/`
    // but rejected with `module_structure.invalid_layer` inside `src/modules/*`.
    // Application modules consume these framework facilities (auth, discovery,
    // OpenAPI, pipeline) via Core contracts; they do not host them.
    packageOnlyDirectories: [
        'Attribute',
        'Auth',
        'Discovery',
        'OpenApi',
        'Pipeline',
    ],
    requiredPackageRootEntries: ['composer.json', 'src'],
    // Production packages must NEVER ship demo / sandbox / playground /
    // example / fake / test-app / experimental code anywhere in their tree.
    // Local demo modules belong under `src/modules/<demo-module-name>/`;
    // test fixtures belong under `<package>/tests/Fixtures/`.
    // Triggers `module_structure.production_package_pollution` (recursive
    // walk of the whole package tree, except the `tests/` sub-tree which is
    // out of scope per § 10 of MODULE_STRUCTURE.md).
    forbiddenInProductionPackages: [
        'Demo',
        'Demos',
        'Example',
        'Examples',
        'Playground',
        'Playgrounds',
        'Sandbox',
        'Sandboxes',
        'Sample',
        'Samples',
        'TestApp',
        'TestApps',
        'Fake',
        'Fakes',
        'Experimental',
        'Experiment',
        'Experiments',
    ],
    packageSpecificCodeRoot: $packageSpecificCodeRoot,
);
