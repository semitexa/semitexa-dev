<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Evidence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceData;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceKind;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceStore;

/**
 * Evidence kept private with a passport and an expiry, instead of a folder per
 * tool that nothing ever emptied (screenshots from June still waiting in
 * October to be shown as "proof").
 */
final class EvidenceStoreTest extends TestCase
{
    private string $root;
    private EvidenceStore $store;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-evidence-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/tmp', 0777, true);
        $this->store = new EvidenceStore($this->root);
        $this->now = new \DateTimeImmutable('2026-10-02T12:00:00+00:00');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function file(string $name, string $contents = 'pixels'): string
    {
        file_put_contents($this->root . '/var/tmp/' . $name, $contents);

        return 'var/tmp/' . $name;
    }

    #[Test]
    public function a_recorded_file_gets_a_private_passport_and_an_expiry(): void
    {
        $record = $this->store->add($this->file('shot.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, 'cart after the fix', 'agent-1', 14, false, $this->now);

        self::assertMatchesRegularExpression('/^ev-20261002-120000-[0-9a-f]{6}$/', $record->id);
        self::assertSame('2026-10-16', $record->expiresAt->format('Y-m-d'));
        self::assertSame('private', $record->toArray()['visibility']);
        self::assertSame('var/tmp/shot.png', $record->source);
        self::assertSame(hash('sha256', 'pixels'), $record->sha256);
        self::assertFileExists($this->store->fileOf($record));
        self::assertFileExists($this->root . '/var/tmp/shot.png', 'copied by default, the original is the caller\'s');
        self::assertSame(0700, fileperms(dirname($this->store->fileOf($record))) & 0777);
        self::assertEquals($record, $this->store->find($record->id));
    }

    #[Test]
    public function move_takes_the_file_out_of_where_it_was(): void
    {
        $record = $this->store->add($this->file('trace.json', '{}'), EvidenceKind::Trace, EvidenceData::Real, '', 'cli', 3, true, $this->now);

        self::assertFileDoesNotExist($this->root . '/var/tmp/trace.json');
        self::assertFileExists($this->store->fileOf($record));
    }

    #[Test]
    public function a_file_outside_the_project_or_already_stored_is_refused(): void
    {
        $outside = sys_get_temp_dir() . '/semitexa-evidence-outside-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($outside, 'x');
        try {
            $this->assertRefused(fn () => $this->store->add($outside, EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, false, $this->now), 'outside the project');
        } finally {
            unlink($outside);
        }
        $this->assertRefused(fn () => $this->store->add('var/tmp/missing.png', EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, false, $this->now), 'copy a file from elsewhere');

        $record = $this->store->add($this->file('a.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, false, $this->now);
        $stored = substr($this->store->fileOf($record), strlen($this->root) + 1);
        $this->assertRefused(fn () => $this->store->add($stored, EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, false, $this->now), 'already in the evidence store');
    }

    #[Test]
    public function evidence_is_kept_for_a_review_not_archived(): void
    {
        $this->assertRefused(fn () => $this->store->add($this->file('a.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 0, false, $this->now), '1..90');
        $this->assertRefused(fn () => $this->store->add($this->file('b.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 91, false, $this->now), '1..90');
    }

    #[Test]
    public function prune_removes_what_expired_and_only_that(): void
    {
        $old = $this->store->add($this->file('old.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 1, false, $this->now->modify('-12 hours'));
        $fresh = $this->store->add($this->file('fresh.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, false, $this->now);
        $later = $this->now->modify('+1 day');

        self::assertSame([$old->id], array_map(static fn ($r) => $r->id, $this->store->prune($later, true)));
        self::assertNotNull($this->store->find($old->id), 'a dry run removes nothing');

        $this->store->prune($later, false);
        self::assertNull($this->store->find($old->id));
        self::assertDirectoryDoesNotExist($this->store->dir() . '/' . $old->id);
        self::assertNotNull($this->store->find($fresh->id));
    }

    #[Test]
    public function recording_does_not_prune_on_its_own(): void
    {
        // add() runs under every command's inbox adoption, `prune --dry-run`
        // included: pruning there deleted evidence on a dry run. The command
        // prunes after an explicit `ai:evidence add` instead.
        $old = $this->store->add($this->file('old.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 1, false, $this->now->modify('-5 days'));
        $this->store->add($this->file('new.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, false, $this->now);

        self::assertNotNull($this->store->find($old->id));
    }

    #[Test]
    public function a_file_named_like_the_passport_is_refused_not_overwritten(): void
    {
        $this->assertRefused(fn () => $this->store->add($this->file('meta.json', '{"report":1}'), EvidenceKind::Report, EvidenceData::Synthetic, '', 'cli', 14, true, $this->now), 'name of the passport');
        self::assertSame('{"report":1}', file_get_contents($this->root . '/var/tmp/meta.json'), 'the file stays where it was');
    }

    #[Test]
    public function moving_a_link_is_refused_and_what_it_points_at_stays(): void
    {
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/logo.png', 'asset');
        symlink($this->root . '/src/logo.png', $this->root . '/var/tmp/shot.png');

        $this->assertRefused(fn () => $this->store->add('var/tmp/shot.png', EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, true, $this->now), 'is a link');
        self::assertFileExists($this->root . '/src/logo.png');

        // Copying is fine: the store keeps its own bytes.
        $record = $this->store->add('var/tmp/shot.png', EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, false, $this->now);
        self::assertFalse(is_link($this->store->fileOf($record)));
        self::assertFileExists($this->root . '/src/logo.png');
    }

    #[Test]
    public function a_linked_folder_in_the_store_is_neither_listed_nor_emptied(): void
    {
        $record = $this->store->add($this->file('a.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 1, false, $this->now->modify('-5 days'));
        // The same passport behind a link named like an evidence folder.
        $elsewhere = $this->root . '/elsewhere';
        rename($this->store->dir() . '/' . $record->id, $elsewhere);
        symlink($elsewhere, $this->store->dir() . '/' . $record->id);

        self::assertSame([], $this->store->all()['records']);
        $this->store->prune($this->now, false);
        self::assertFileExists($elsewhere . '/a.png', 'prune followed the link');
        self::assertNull($this->store->find($record->id), 'a file behind the link would be served');
    }

    #[Test]
    public function prune_reports_only_what_it_could_remove(): void
    {
        $record = $this->store->add($this->file('a.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 1, false, $this->now->modify('-5 days'));
        $dir = $this->store->dir() . '/' . $record->id;
        chmod($dir, 0500); // its files cannot be unlinked, as with root-owned ones
        try {
            self::assertSame([], $this->store->prune($this->now, false));
            self::assertNotNull($this->store->find($record->id));
        } finally {
            chmod($dir, 0700);
        }
    }

    #[Test]
    public function a_folder_without_a_passport_is_reported_never_removed(): void
    {
        mkdir($this->store->dir() . '/somebody-elses', 0777, true);
        file_put_contents($this->store->dir() . '/somebody-elses/x.png', 'x');

        self::assertSame(['somebody-elses'], $this->store->all()['unreadable']);
        $this->store->prune($this->now->modify('+1 year'), false);
        self::assertFileExists($this->store->dir() . '/somebody-elses/x.png');
    }

    #[Test]
    public function a_passport_does_not_vouch_for_a_folder_it_was_copied_into(): void
    {
        $record = $this->store->add($this->file('a.png'), EvidenceKind::Screenshot, EvidenceData::Synthetic, '', 'cli', 14, false, $this->now);
        $twin = 'ev-20261002-120001-abcdef';
        mkdir($this->store->dir() . '/' . $twin, 0700);
        copy($this->store->dir() . '/' . $record->id . '/meta.json', $this->store->dir() . '/' . $twin . '/meta.json');

        self::assertNull($this->store->find($twin));
        self::assertContains($twin, $this->store->all()['unreadable']);
    }

    #[Test]
    public function an_id_is_never_a_path(): void
    {
        self::assertNull($this->store->find('../../etc'));
        self::assertNull($this->store->find('ev-20261002-120000-abcdef/../x'));
    }

    #[Test]
    public function folders_tools_wrote_evidence_into_before_are_listed_not_touched(): void
    {
        mkdir($this->root . '/var/os-dev-shots', 0777, true);
        file_put_contents($this->root . '/var/os-dev-shots/desk.png', str_repeat('x', 2048));
        touch($this->root . '/var/os-dev-shots/desk.png', strtotime('2026-07-02T10:00:00Z'));
        mkdir($this->root . '/var/review-graph', 0777, true);
        file_put_contents($this->root . '/var/review-graph/.gitkeep', '');

        self::assertSame(
            [['dir' => 'var/os-dev-shots', 'kind' => 'screenshot', 'files' => 1, 'bytes' => 2048, 'oldest' => '2026-07-02']],
            $this->store->unregistered(),
        );
    }

    private function assertRefused(callable $add, string $because): void
    {
        try {
            $add();
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($because, $e->getMessage());
        }
    }
}
