<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify\Structure;

/**
 * Loads the executable module-structure spec from
 * `packages/semitexa-dev/config/module-structure.php`.
 *
 * The spec file `return`s a {@see ModuleStructureSpec} value. Loading is
 * cheap (a single `require`); this loader only adds a tiny in-process cache
 * keyed by spec-file mtime so a long-running worker does not re-parse on
 * every ai:verify invocation when the file has not changed on disk.
 *
 * If the spec file is missing the loader throws — there is no implicit
 * fallback. The strict-allowlist contract is meaningless without an explicit
 * spec.
 */
final class ModuleStructureSpecLoader
{
    public const SPEC_REL_PATH = 'packages/semitexa-dev/config/module-structure.php';

    /** @var array<string, array{mtime:int, spec:ModuleStructureSpec}> */
    private array $cache = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $specPathOverride = null,
    ) {}

    /**
     * Resolution order:
     *
     *   1. `$specPathOverride` if set (test injection / per-call override).
     *   2. `<projectRoot>/packages/semitexa-dev/config/module-structure.php`
     *      — the canonical location when running from the monorepo.
     *   3. The dev package's own bundled spec, located via `__DIR__`. This
     *      ensures the loader works in any consumer project that installs
     *      `semitexa/dev` via Composer (the spec is shipped inside the
     *      package, so it is always present alongside this loader).
     *
     * Step 3 is **not** a fallback to a *different* spec — it is the same
     * spec, located via Composer's autoload path instead of the working
     * directory. It removes any chance of a `RuntimeException` when ai:verify
     * runs against a project that does not literally contain the dev package
     * at the canonical relative path.
     */
    public function load(): ModuleStructureSpec
    {
        $abs = $this->resolveSpecPath();

        if (!is_file($abs)) {
            throw new \RuntimeException(
                'Module structure spec missing at ' . $abs
                . ' — ai:verify cannot enforce a strict allowlist without an explicit spec.'
                . ' See packages/semitexa-docs/docs/MODULE_STRUCTURE.md for the prose mirror.',
            );
        }

        $mtime = (int) @filemtime($abs);
        if (isset($this->cache[$abs]) && $this->cache[$abs]['mtime'] === $mtime) {
            return $this->cache[$abs]['spec'];
        }

        $spec = require $abs;
        if (!$spec instanceof ModuleStructureSpec) {
            throw new \RuntimeException(
                'Module structure spec at ' . $abs . ' must return a ' . ModuleStructureSpec::class . ' instance.',
            );
        }

        $this->cache[$abs] = ['mtime' => $mtime, 'spec' => $spec];
        return $spec;
    }

    private function resolveSpecPath(): string
    {
        if ($this->specPathOverride !== null) {
            return $this->specPathOverride;
        }
        $atProjectRoot = $this->projectRoot . '/' . self::SPEC_REL_PATH;
        if (is_file($atProjectRoot)) {
            return $atProjectRoot;
        }
        // Bundled location, relative to this loader file. Walk up from
        // `<dev>/src/Ai/Verify/Structure/` to `<dev>/config/`.
        return dirname(__DIR__, 4) . '/config/module-structure.php';
    }
}
