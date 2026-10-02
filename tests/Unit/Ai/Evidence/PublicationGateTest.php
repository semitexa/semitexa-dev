<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Evidence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceData;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceKind;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceRecord;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceStore;
use Semitexa\Dev\Application\Service\Ai\Evidence\PrivateEvidencePatterns;
use Semitexa\Dev\Application\Service\Ai\Evidence\PublicationGate;

/**
 * What may leave the machine, before anyone is asked: every case both ways.
 */
final class PublicationGateTest extends TestCase
{
    private string $root;
    private EvidenceStore $store;
    private PublicationGate $gate;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-gate-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/tmp', 0777, true);
        $this->store = new EvidenceStore($this->root);
        $this->gate = new PublicationGate(PrivateEvidencePatterns::load());
        $this->now = new \DateTimeImmutable('2026-10-02T12:00:00+00:00');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function record(string $name, string $contents, EvidenceKind $kind, EvidenceData $data): EvidenceRecord
    {
        file_put_contents($this->root . '/var/tmp/' . $name, $contents);

        return $this->store->add('var/tmp/' . $name, $kind, $data, '', 'cli', 14, false, $this->now);
    }

    /** @return list<string> */
    private function refusals(EvidenceRecord $record, bool $allowReal = false, ?\DateTimeImmutable $at = null): array
    {
        return $this->gate->refusals($record, $this->store->fileOf($record), $allowReal, $at ?? $this->now);
    }

    #[Test]
    public function a_screenshot_of_synthetic_data_may_go(): void
    {
        self::assertSame([], $this->refusals($this->record('cart.png', "\x89PNG", EvidenceKind::Screenshot, EvidenceData::Synthetic)));
    }

    #[Test]
    public function a_picture_of_real_data_never_goes_whatever_is_allowed(): void
    {
        $shot = $this->record('inbox.png', "\x89PNG", EvidenceKind::Screenshot, EvidenceData::Real);
        self::assertStringContainsString('retake it on synthetic data', $this->refusals($shot, true)[0]);

        // Declared as a report, it is still a picture.
        $renamed = $this->record('inbox2.webm', 'video', EvidenceKind::Report, EvidenceData::Real);
        self::assertStringContainsString('retake it on synthetic data', $this->refusals($renamed, true)[0]);
    }

    #[Test]
    public function real_text_goes_only_when_the_operator_allows_it(): void
    {
        $trace = $this->record('trace.json', '{"route":"/orders"}', EvidenceKind::Trace, EvidenceData::Real);

        self::assertStringContainsString('--allow-real-data', $this->refusals($trace)[0]);
        self::assertSame([], $this->refusals($trace, true));
    }

    #[Test]
    public function a_secret_or_a_workstation_path_in_text_refuses_even_when_allowed(): void
    {
        $log = $this->record('run.log', "ok\nAuthorization: ghp_" . str_repeat('a1', 18) . "\nsaved /home/taras/x\n", EvidenceKind::Log, EvidenceData::Real);

        $refusals = $this->refusals($log, true);
        self::assertCount(2, $refusals);
        self::assertStringStartsWith('line 2: token', $refusals[0]);
        self::assertStringStartsWith('line 3: local-path', $refusals[1]);
    }

    #[Test]
    public function what_cannot_be_read_cannot_be_checked_so_it_does_not_go(): void
    {
        $zip = $this->record('bundle.zip', "PK\x03\x04", EvidenceKind::Report, EvidenceData::Synthetic);
        self::assertStringContainsString('nothing can check it', $this->refusals($zip)[0]);

        $binary = $this->record('dump.json', "{\0}", EvidenceKind::Report, EvidenceData::Synthetic);
        self::assertStringContainsString('nothing can check it', $this->refusals($binary)[0]);
    }

    #[Test]
    public function an_expired_or_changed_file_does_not_go(): void
    {
        $shot = $this->record('cart.png', "\x89PNG", EvidenceKind::Screenshot, EvidenceData::Synthetic);
        self::assertStringContainsString('expired', $this->refusals($shot, false, $this->now->modify('+15 days'))[0]);

        file_put_contents($this->store->fileOf($shot), 'something else');
        self::assertStringContainsString('changed after it was recorded', $this->refusals($shot)[0]);
    }

    #[Test]
    public function an_approval_is_recorded_on_the_passport(): void
    {
        $shot = $this->record('cart.png', "\x89PNG", EvidenceKind::Screenshot, EvidenceData::Synthetic);
        $this->store->save($shot->withPublication('PR semitexa/dev#120', 'taras', $this->now));

        $row = $this->store->find($shot->id)?->toArray();
        self::assertSame('published', $row['visibility'] ?? null);
        self::assertSame([['to' => 'PR semitexa/dev#120', 'approved_at' => '2026-10-02T12:00:00+00:00', 'approved_by' => 'taras']], $row['publications'] ?? null);
    }
}
