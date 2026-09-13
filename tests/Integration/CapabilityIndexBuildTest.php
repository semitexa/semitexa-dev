<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;

/**
 * The shipped index and the gate that keeps it honest.
 *
 * This file is the only reason a project can hear about a package it never
 * installed, so two things have to hold: the index says what the code says, and
 * the check that asserts so cannot be fooled.
 */
final class CapabilityIndexBuildTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function capabilities(): array
    {
        return [
            ['id' => 'a.one', 'summary' => 's', 'kind' => 'mechanism'],
            ['id' => 'b.two', 'summary' => 't', 'kind' => 'package'],
        ];
    }

    #[Test]
    public function the_hash_covers_the_payload_and_not_the_timestamp(): void
    {
        // generated_at changes on every run. Hashing the whole file would make
        // the freshness gate fail every time, and a gate that always fails gets
        // switched off within a week.
        $first = CapabilityIndex::build(self::capabilities(), ['semitexa/core']);
        $second = CapabilityIndex::build(self::capabilities(), ['semitexa/core']);

        self::assertSame($first['content_hash'], $second['content_hash']);
    }

    #[Test]
    public function changing_a_capability_changes_the_hash(): void
    {
        $changed = self::capabilities();
        $changed[0]['summary'] = 'different';

        self::assertNotSame(
            CapabilityIndex::hash(self::capabilities()),
            CapabilityIndex::hash($changed),
        );
    }

    #[Test]
    public function removing_a_capability_changes_the_hash(): void
    {
        // The mutation that slipped past the first version of the check: a
        // capability deleted straight out of the file. It passed because the
        // check compared the hash the file CLAIMED rather than hashing what the
        // file contained. A gate that believes the artifact it is checking is
        // not a gate.
        $shortened = [self::capabilities()[0]];

        self::assertNotSame(
            CapabilityIndex::hash(self::capabilities()),
            CapabilityIndex::hash($shortened),
        );
    }

    #[Test]
    public function a_tampered_index_does_not_match_its_own_claimed_hash(): void
    {
        // Recomputing from content is what makes hand-editing detectable, so
        // pin that the claimed value and the real one come apart.
        $payload = CapabilityIndex::build(self::capabilities(), ['semitexa/core']);
        $payload['capabilities'] = [self::capabilities()[0]];

        self::assertNotSame(
            $payload['content_hash'],
            CapabilityIndex::hash($payload['capabilities']),
        );
    }

    #[Test]
    public function the_shipped_index_matches_its_own_content(): void
    {
        // Guards the artifact actually in the repository: if someone edits it by
        // hand and forgets to rebuild, this fails here rather than shipping a
        // catalog that disagrees with the code.
        $path = CapabilityIndex::path(dirname(__DIR__, 4));
        $shipped = CapabilityIndex::read($path);

        self::assertIsArray($shipped, 'the index is missing at ' . $path);
        self::assertSame(
            $shipped['content_hash'],
            CapabilityIndex::hash(array_values((array) $shipped['capabilities'])),
            'the shipped index has been edited by hand — run dev:capability-index:build',
        );
    }

    #[Test]
    public function in_sync_compares_declared_content_against_shipped_content(): void
    {
        $live = self::capabilities();

        self::assertTrue(
            CapabilityIndex::isInSync($live, CapabilityIndex::build($live, ['semitexa/core'])),
            'a freshly built index must agree with what it was built from',
        );
    }

    #[Test]
    public function in_sync_is_false_when_the_shipped_file_claims_a_hash_it_does_not_have(): void
    {
        // The failure mode that mattered: an index edited by hand keeps the old
        // content_hash, so a gate reading that field passes while a capability
        // has been deleted out of the file. isInSync must hash the content.
        $tampered = CapabilityIndex::build(self::capabilities(), ['semitexa/core']);
        array_pop($tampered['capabilities']);

        self::assertFalse(CapabilityIndex::isInSync(self::capabilities(), $tampered));
    }

    #[Test]
    public function in_sync_is_false_when_there_is_no_index_at_all(): void
    {
        // A missing file must never read as agreement — that is how a gate
        // reports pass on a project that ships no index.
        self::assertFalse(CapabilityIndex::isInSync(self::capabilities(), null));
        self::assertFalse(CapabilityIndex::isInSync(self::capabilities(), ['artifact' => 'x']));
    }

    #[Test]
    public function the_monorepo_is_recognised_as_a_full_view(): void
    {
        // The condition the freshness gate keys on. If this ever went false in
        // the monorepo, the gate would skip everywhere and read as pass.
        $root = dirname(__DIR__, 4);

        self::assertTrue(CapabilityIndex::isFullView($root));
        self::assertFalse(CapabilityIndex::isFullView(sys_get_temp_dir()));
    }

    #[Test]
    public function the_shipped_index_carries_both_shapes_and_a_timestamp(): void
    {
        $shipped = (array) CapabilityIndex::read(CapabilityIndex::path(dirname(__DIR__, 4)));
        $kinds = array_unique(array_column((array) $shipped['capabilities'], 'kind'));
        sort($kinds);

        self::assertSame(['mechanism', 'package'], $kinds, 'package-level capabilities are missing from the index');
        self::assertNotSame('', (string) $shipped['generated_at'], 'an undated snapshot cannot be judged stale');
    }

    /**
     * The index is what an agent reads to ask "what is in this ecosystem", and
     * answering with the package list alone left a gap every agent then spent
     * time re-deriving: `packages/semitexa-*` is the glob that DEFINES the
     * package set, and two directories inside it are not Composer packages —
     * semitexa-installer publishes a Docker image, semitexa-companion is a
     * browser extension. A release over eleven directories reporting ten
     * packages is not a missing tag.
     */
    #[Test]
    public function the_index_names_the_directories_that_are_not_packages(): void
    {
        // Against a FIXTURE tree, not the checkout. The authoring workspace has
        // semitexa-installer and semitexa-companion; the release clone has
        // neither, because it installs Composer packages and nothing else — so
        // naming them here made the test pass in one environment and fail in
        // the other for no reason anyone could act on.
        $root = sys_get_temp_dir() . '/semitexa-nonpkg-' . uniqid('', true);
        mkdir($root . '/packages/semitexa-probe-docker', 0777, true);
        mkdir($root . '/packages/semitexa-probe-extension', 0777, true);
        mkdir($root . '/packages/semitexa-probe-package', 0777, true);

        try {
            touch($root . '/packages/semitexa-probe-docker/Dockerfile');
            touch($root . '/packages/semitexa-probe-extension/manifest.json');
            file_put_contents($root . '/packages/semitexa-probe-package/composer.json', '{"name":"semitexa/probe"}');
            // A marker file does NOT make a package a non-package: this one has
            // both, and the composer.json has to win.
            touch($root . '/packages/semitexa-probe-package/Dockerfile');

            $found = CapabilityIndex::nonPackageDirectoriesOnDisk($root);

            self::assertSame(
                ['semitexa-probe-docker', 'semitexa-probe-extension'],
                array_keys($found),
                'classified by marker file, and only for directories without a composer.json',
            );
            self::assertStringContainsString('docker', strtolower($found['semitexa-probe-docker']));
            self::assertStringContainsString('extension', strtolower($found['semitexa-probe-extension']));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * And whatever the real checkout holds is classified consistently — the
     * invariant that survives both environments.
     */
    #[Test]
    public function nothing_listed_as_a_non_package_has_a_composer_json(): void
    {
        $found = CapabilityIndex::nonPackageDirectoriesOnDisk(ProjectRoot::get());

        foreach (array_keys($found) as $directory) {
            self::assertFileDoesNotExist(
                ProjectRoot::get() . '/packages/' . $directory . '/composer.json',
                "{$directory} has a composer.json, so it IS a package and must not be listed here",
            );
        }

        self::assertIsArray($found);
    }

    /** And a package is never listed among them. */
    #[Test]
    public function a_real_package_is_not_listed_as_a_non_package(): void
    {
        $found = CapabilityIndex::nonPackageDirectoriesOnDisk(ProjectRoot::get());

        self::assertArrayNotHasKey('semitexa-core', $found);
        self::assertArrayNotHasKey('semitexa-ssr', $found);
    }

    /**
     * Carried in the payload, beside `packages` rather than inside it — and
     * NOT folded into content_hash, which answers "have the capabilities
     * changed". A new directory has changed no capability, and a hash that
     * moved would report the shipped index as stale for no reason.
     */
    #[Test]
    public function the_payload_carries_them_without_disturbing_the_hash(): void
    {
        $without = CapabilityIndex::build(self::capabilities(), ['semitexa/core']);
        $with = CapabilityIndex::build(self::capabilities(), ['semitexa/core'], ['semitexa-installer' => 'docker project']);

        self::assertSame([], $without['not_packages'], 'the default is empty, not absent');
        self::assertSame(['semitexa-installer' => 'docker project'], $with['not_packages']);
        self::assertSame($without['content_hash'], $with['content_hash']);
    }

    /**
     * And because it is outside content_hash, the freshness gate has to compare
     * it SEPARATELY — otherwise a derived inventory drifts in silence and
     * `--check` keeps reporting the index current while this field is wrong.
     */
    #[Test]
    public function drift_in_not_packages_is_noticed_even_though_the_hash_cannot_see_it(): void
    {
        $capabilities = self::capabilities();
        $shipped = CapabilityIndex::build($capabilities, ['semitexa/core'], ['semitexa-installer' => 'docker project']);

        // A directory appears. The capability hash is blind to it, by design.
        $live = [
            'semitexa-installer' => 'docker project',
            'semitexa-companion' => 'browser extension',
        ];

        self::assertTrue(
            CapabilityIndex::isInSync($capabilities, $shipped),
            'the capability hash cannot see this, which is why the separate check exists',
        );
        self::assertFalse(CapabilityIndex::nonPackagesAreInSync($live, $shipped));
    }

    /** A marker file changing type is drift too, not just a directory appearing. */
    #[Test]
    public function a_changed_classification_counts_as_drift(): void
    {
        $shipped = CapabilityIndex::build(self::capabilities(), ['semitexa/core'], ['semitexa-installer' => 'docker project']);

        self::assertFalse(CapabilityIndex::nonPackagesAreInSync(
            ['semitexa-installer' => 'browser extension'],
            $shipped,
        ));
        self::assertTrue(CapabilityIndex::nonPackagesAreInSync(
            ['semitexa-installer' => 'docker project'],
            $shipped,
        ));
    }

    /**
     * An index built before the field existed has no `not_packages` at all.
     * That is stale for this purpose — which is what makes the gate notice on
     * its first run rather than silently accepting the older shape forever.
     */
    #[Test]
    public function an_index_without_the_field_is_stale_rather_than_accepted(): void
    {
        self::assertFalse(CapabilityIndex::nonPackagesAreInSync([], ['capabilities' => []]));
    }
}
