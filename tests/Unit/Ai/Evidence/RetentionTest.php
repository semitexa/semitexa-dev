<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Evidence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceData;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceKind;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceStore;
use Semitexa\Dev\Application\Service\Ai\Evidence\ScratchRetention;
use Semitexa\Dev\Application\Service\Trace\TraceRetention;
use Semitexa\ProjectGraph\Application\Service\Query\ExportLocation;

/**
 * What used to stay forever (2026-10-02): 9376 request traces, 1.7 GB of
 * var/tmp, graph exports wherever --output pointed.
 */
final class RetentionTest extends TestCase
{
    private string $root;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-retention-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/tmp', 0777, true);
        $this->now = new \DateTimeImmutable('2026-10-02T12:00:00+00:00');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function request_traces_go_after_a_week_by_the_day_in_their_name(): void
    {
        $dir = $this->root . '/var/trace';
        mkdir($dir);
        foreach (['20260924-101010-aaaa1111.json', '20260925-000000-bbbb2222.json', '20261002-090000-cccc3333.json', 'notes.json', '20260901-000000-dddd4444.json.tmp'] as $name) {
            file_put_contents($dir . '/' . $name, '{}');
        }
        // A fresh mtime does not save an old trace: the name says when it was written.
        touch($dir . '/20260924-101010-aaaa1111.json', $this->now->getTimestamp());

        self::assertSame([$dir . '/20260924-101010-aaaa1111.json'], TraceRetention::sweep($dir, $this->now->getTimestamp(), true));
        self::assertFileExists($dir . '/20260924-101010-aaaa1111.json', 'dry');

        TraceRetention::sweep($dir, $this->now->getTimestamp());
        self::assertSame(
            ['20260901-000000-dddd4444.json.tmp', '20260925-000000-bbbb2222.json', '20261002-090000-cccc3333.json', 'notes.json'],
            array_values(array_diff(scandir($dir) ?: [], ['.', '..'])),
        );
    }

    #[Test]
    public function the_daily_sweep_goes_a_batch_at_a_time_on_the_request_path(): void
    {
        // Thousands of unlinks in one request blocked the worker that served it.
        $dir = $this->root . '/var/trace';
        mkdir($dir);
        for ($i = 0; $i < TraceRetention::BATCH + 3; $i++) {
            touch(sprintf('%s/20260901-%06d-aaaa1111.json', $dir, $i));
        }
        $at = $this->now->getTimestamp();

        TraceRetention::sweepDaily($dir, $at);
        self::assertCount(3, glob($dir . '/*.json') ?: []);
        TraceRetention::sweepDaily($dir, $at);
        self::assertCount(0, glob($dir . '/*.json') ?: [], 'the day is not done until the batch comes back short');

        touch($dir . '/20260901-999999-bbbb2222.json');
        TraceRetention::sweepDaily($dir, $at);
        self::assertCount(1, glob($dir . '/*.json') ?: [], 'once done, the day is done');
    }

    #[Test]
    public function scratch_is_stale_only_when_nothing_inside_changed_for_two_weeks(): void
    {
        $old = $this->now->modify('-30 days')->getTimestamp();
        mkdir($this->root . '/var/tmp/abandoned/deep', 0777, true);
        file_put_contents($this->root . '/var/tmp/abandoned/deep/db.sqlite', str_repeat('x', 100));
        touch($this->root . '/var/tmp/abandoned/deep/db.sqlite', $old);
        touch($this->root . '/var/tmp/abandoned/deep', $old);
        touch($this->root . '/var/tmp/abandoned', $old);

        mkdir($this->root . '/var/tmp/harness', 0777, true);
        file_put_contents($this->root . '/var/tmp/harness/fixture.txt', 'old');
        touch($this->root . '/var/tmp/harness/fixture.txt', $old);
        file_put_contents($this->root . '/var/tmp/harness/fuzz.py', 'edited today');
        touch($this->root . '/var/tmp/harness/fuzz.py', $this->now->getTimestamp());

        file_put_contents($this->root . '/var/tmp/old.log', 'x');
        touch($this->root . '/var/tmp/old.log', $old);
        file_put_contents($this->root . '/var/tmp/.project-graph.lock', '');
        touch($this->root . '/var/tmp/.project-graph.lock', $old);

        $retention = new ScratchRetention($this->root);
        $stale = $retention->stale($this->now);
        self::assertSame(['var/tmp/abandoned', 'var/tmp/old.log'], array_column($stale, 'path'));
        self::assertSame(100, $stale[0]['bytes']);

        $retention->remove($stale);
        self::assertDirectoryDoesNotExist($this->root . '/var/tmp/abandoned');
        self::assertFileDoesNotExist($this->root . '/var/tmp/old.log');
        self::assertFileExists($this->root . '/var/tmp/harness/fixture.txt', 'kept with the harness edited today');
        self::assertFileExists($this->root . '/var/tmp/.project-graph.lock');
    }

    #[Test]
    public function removing_scratch_never_follows_a_link_out_of_it(): void
    {
        $outside = $this->root . '/precious';
        mkdir($outside);
        file_put_contents($outside . '/keep.txt', 'mine');
        symlink($outside, $this->root . '/var/tmp/link');

        (new ScratchRetention($this->root))->remove([
            ['path' => 'var/tmp/link', 'bytes' => 0, 'newest' => '2026-01-01'],
            ['path' => 'var/tmp/../precious', 'bytes' => 0, 'newest' => '2026-01-01'],
        ]);

        self::assertFileExists($outside . '/keep.txt');
        self::assertFalse(is_link($this->root . '/var/tmp/link'));
    }

    #[Test]
    public function a_graph_export_in_the_inbox_becomes_private_evidence_of_real_data(): void
    {
        // The contract between two packages: project-graph writes the hint,
        // this store adopts it. Neither depends on the other's code at runtime.
        mkdir($this->root . '/' . EvidenceStore::INBOX, 0777, true);
        $export = $this->root . '/' . ExportLocation::DEFAULT_DIR . '/graph-Orders.html';
        file_put_contents($export, '<html></html>');
        ExportLocation::hintFor($this->root, $export, 'Orders');

        $store = new EvidenceStore($this->root);
        $result = $store->adopt($this->now);

        self::assertSame([], $result['rejected']);
        self::assertCount(1, $result['adopted']);
        $record = $result['adopted'][0];
        self::assertSame([EvidenceKind::GraphExport, EvidenceData::Real, 'ai:review-graph:show', 'graph-Orders.html'], [$record->kind, $record->data, $record->createdBy, $record->file]);
        self::assertSame('2026-10-16', $record->expiresAt->format('Y-m-d'));
        self::assertSame([], glob($this->root . '/' . EvidenceStore::INBOX . '/*') ?: [], 'moved out, hint gone');
        self::assertSame([], $store->all()['unreadable'], 'the inbox is not a passportless folder');
    }

    #[Test]
    public function an_inbox_file_with_a_bad_hint_stays_and_says_why(): void
    {
        mkdir($this->root . '/' . EvidenceStore::INBOX, 0777, true);
        $file = $this->root . '/' . EvidenceStore::INBOX . '/shot.png';
        file_put_contents($file, 'x');
        file_put_contents($file . '.evidence.json', '{"schema":"semitexa.evidence-inbox/v1","kind":"screenshot"}');

        $result = (new EvidenceStore($this->root))->adopt($this->now);

        self::assertSame([], $result['adopted']);
        self::assertStringContainsString('with a kind and data', $result['rejected']['shot.png']);
        self::assertFileExists($file);
    }
}
