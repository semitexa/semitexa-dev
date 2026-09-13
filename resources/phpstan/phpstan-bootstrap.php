<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap shim — referenced from phpstan.neon's `bootstrapFiles`.
 *
 * Lives at project root (not inside packages/semitexa-core/bootstrap/) so the
 * release-readiness flow can sync it into the rls release clone before the
 * release-clone's path-repo'd semitexa-core has caught up to a develop->master
 * merge that introduces new bootstrap helpers. PHPStan refuses to start when
 * a referenced bootstrapFile is missing.
 *
 * Responsibility: register PSR-4 mappings for local modules
 * (src/modules/<Name>/src) on the live Composer ClassLoader so PHPStan can
 * discover module classes when analyzing files that reference them.
 *
 * Production runtime registers these via the LocalModuleAutoloadPhase build
 * phase; PHPStan never runs that phase, so it needs an explicit hook here.
 *
 * The class_exists() guard makes this a no-op on installations where
 * semitexa-core hasn't yet shipped LocalModuleAutoloadRegistrar (e.g. an rls
 * clone running against an older master tag) — those clones either don't have
 * src/modules/ at all, or their modules are registered by other means.
 *
 * Idempotent: addPsr4 inside the registrar merges directories.
 */

if (class_exists(\Semitexa\Core\Boot\LocalModuleAutoloadRegistrar::class)) {
    \Semitexa\Core\Boot\LocalModuleAutoloadRegistrar::register();
}
