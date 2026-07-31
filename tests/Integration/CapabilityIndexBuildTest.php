<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
