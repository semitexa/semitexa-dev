<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Explorer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Explorer\FieldHintReflector;
use Semitexa\Dev\Application\Service\Explorer\TraceLocator;

enum HintedStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

final class HintedPayload
{
    private int $perPage = 20;
    private ?string $note = null;
    private string $title = '';

    public function setStatus(HintedStatus $status): void {}
    public function setPerPage(int $perPage): void { $this->perPage = $perPage; }
    public function setContactEmail(string $email): void {}
    public function setLimit(int $limit = 50): void {}
    public function setNote(?string $note): void { $this->note = $note; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function getPerPage(): int { return $this->perPage; }
}

final class FieldHintReflectorTest extends TestCase
{
    #[Test]
    public function it_reads_enum_cases_defaults_and_formats_from_setters(): void
    {
        $hints = FieldHintReflector::hints(HintedPayload::class);

        self::assertSame(['draft', 'live'], $hints['status']['enum']);
        self::assertSame(20, $hints['perPage']['default']);
        self::assertSame(50, $hints['limit']['default']);
        self::assertSame('email', $hints['contactEmail']['format']);
        self::assertArrayNotHasKey('note', $hints, 'a null default is no hint');
        self::assertArrayNotHasKey('title', $hints, 'an empty-string default is a placeholder, not a hint');
        self::assertSame([], FieldHintReflector::hints('No\\Such\\Payload'));
    }

    #[Test]
    public function it_guesses_formats_by_whole_words_only(): void
    {
        self::assertSame('datetime', FieldHintReflector::format('createdAt'));
        self::assertSame('url', FieldHintReflector::format('download_url'));
        self::assertNull(FieldHintReflector::format('category'), '"at" inside a word is not a timestamp');
    }

    #[Test]
    public function the_trace_locator_accepts_only_explorer_markers(): void
    {
        self::assertTrue(TraceLocator::isToken('explorer-0123456789abcdef'));
        self::assertFalse(TraceLocator::isToken('../../etc/passwd'));
        self::assertFalse(TraceLocator::isToken('quality-0123456789abcdef'));
        self::assertNull(TraceLocator::find('explorer-"}'));
    }
}
