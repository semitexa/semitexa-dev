<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Capability;

/**
 * Shape, location and hashing of the shipped capability index.
 *
 * Kept apart from the command that writes it because the freshness gate and the
 * readers need the same answers — where the file lives, and whether two
 * versions of it say the same thing. Two implementations of "same" would
 * eventually disagree, and the one place that matters is a release gate.
 */
final class CapabilityIndex
{
    public const ARTIFACT = 'semitexa.dev.capability-index/v1';

    /**
     * Where the snapshot lives, relative to the monorepo root.
     *
     * Public because the verify planner has to recognise an edit to this exact
     * file, and the failure diagnostic has to name it. Three copies of one path
     * is the drift this class exists to prevent everywhere else.
     */
    public const RELATIVE_PATH = 'packages/semitexa-dev/resources/capability-index.json';

    /**
     * Below this many Semitexa packages on disk, the working copy is not the
     * monorepo and the index cannot be built or judged from what is visible.
     *
     * Deliberately conservative. Building in a consumer project would silently
     * DROP every capability that project has not installed — turning the one
     * artifact that knows about uninstalled packages into a mirror of what is
     * already visible. Checking there would be worse still: the freshness gate
     * would fail every consumer's `ai:verify` for a file they cannot regenerate.
     */
    public const MIN_PACKAGES_FOR_A_FULL_VIEW = 10;

    /**
     * The file inside this package, resolved from wherever it is installed.
     *
     * In the monorepo that is the path above; in a consumer it sits under
     * `vendor/semitexa/dev`. Resolving from this class's own location works in
     * both without a special case.
     */
    public static function path(string $projectRoot): string
    {
        $monorepo = $projectRoot . '/' . self::RELATIVE_PATH;
        if (is_file($monorepo) || is_dir(dirname($monorepo))) {
            return $monorepo;
        }

        return dirname(__DIR__, 4) . '/resources/capability-index.json';
    }

    /**
     * Semitexa package names present as directories, regardless of what this
     * checkout has installed.
     *
     * Shared by the builder and the freshness gate: both have to decide whether
     * this working copy can see the whole ecosystem, and two answers to that
     * question would mean a gate failing what the builder refuses to fix.
     *
     * @return list<string>
     */
    public static function packagesOnDisk(string $projectRoot): array
    {
        $names = [];
        foreach ((array) glob($projectRoot . '/packages/semitexa-*', GLOB_ONLYDIR) as $dir) {
            $manifest = (string) $dir . '/composer.json';
            if (!is_file($manifest)) {
                continue;
            }
            $composer = json_decode((string) file_get_contents($manifest), true);
            if (is_array($composer) && is_string($composer['name'] ?? null)) {
                $names[] = $composer['name'];
            }
        }
        sort($names);

        return array_values(array_unique($names));
    }

    /** Whether this working copy sees enough of the ecosystem to speak for it. */
    public static function isFullView(string $projectRoot): bool
    {
        return count(self::packagesOnDisk($projectRoot)) >= self::MIN_PACKAGES_FOR_A_FULL_VIEW;
    }

    /**
     * Whether the shipped file says what the code says.
     *
     * Hashes the shipped CONTENT, never the hash it claims for itself. Trusting
     * the stored value means a hand-edited index passes: the first version of
     * this check did exactly that, and a capability deleted straight out of the
     * file went unnoticed. A gate that believes the artifact it is checking is
     * not a gate.
     *
     * @param list<array<string, mixed>> $live
     * @param array<string, mixed>|null $shipped decoded index file, or null when absent
     */
    public static function isInSync(array $live, ?array $shipped): bool
    {
        $capabilities = is_array($shipped['capabilities'] ?? null) ? $shipped['capabilities'] : null;
        if ($capabilities === null) {
            return false;
        }

        return self::hash(array_values($capabilities)) === self::hash($live);
    }

    /**
     * @param list<array<string, mixed>> $capabilities
     * @param list<string> $packages
     * @return array{artifact: string, generated_at: string, count: int, packages: list<string>,
     *               content_hash: string, capabilities: list<array<string, mixed>>}
     */
    public static function build(array $capabilities, array $packages): array
    {
        return [
            'artifact' => self::ARTIFACT,
            // UTC, like every other timestamp the framework writes. Recorded so
            // a reader can see how old the answer is rather than trusting it
            // blindly — an index is a snapshot, and a silent snapshot is the
            // failure mode.
            'generated_at' => gmdate('c'),
            'count' => count($capabilities),
            'packages' => $packages,
            'content_hash' => self::hash($capabilities),
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Fingerprint of the payload ALONE.
     *
     * `generated_at` changes on every run, so hashing the whole file would make
     * a freshness check fail every time and the gate would be turned off within
     * a week. Only the content anybody consumes is hashed.
     *
     * @param list<array<string, mixed>> $capabilities
     */
    public static function hash(array $capabilities): string
    {
        return hash('sha256', (string) json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Combine what is installed with what the snapshot knows, marking each.
     *
     * Pure on purpose. Merging inside the command meant the only way to reach
     * the "available" branch was a checkout missing a package — which the
     * monorepo never is, so the tests covering it passed while doing nothing.
     * Given both lists directly, the interesting case is one line of setup.
     *
     * Installed wins on conflict: the live declaration is the truth for
     * anything present, and the index may be older than the code.
     *
     * @param list<array<string, mixed>> $live
     * @param list<array<string, mixed>> $indexed
     * @return list<array<string, mixed>>
     */
    public static function merge(array $live, array $indexed): array
    {
        $merged = [];
        foreach ($live as $capability) {
            if (!is_string($capability['id'] ?? null)) {
                continue;
            }
            $capability['state'] = 'installed';
            $merged[$capability['id']] = $capability;
        }

        foreach ($indexed as $capability) {
            if (!is_string($capability['id'] ?? null) || isset($merged[$capability['id']])) {
                continue;
            }
            $capability['state'] = 'available';
            $merged[$capability['id']] = $capability;
        }

        $out = array_values($merged);
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $payload */
    public static function write(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
