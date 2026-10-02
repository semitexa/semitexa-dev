<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Evidence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceData;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceKind;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceListQuery;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceRecord;

/**
 * The Evidence view's search, filters, date range, sort and pages — answered
 * on the server, so they are pinned here rather than in a browser.
 */
final class EvidenceListQueryTest extends TestCase
{
    /** @return list<EvidenceRecord> */
    private static function records(): array
    {
        $r = static fn (string $id, string $created, EvidenceKind $kind, EvidenceData $data, string $file, int $bytes, string $note = '', array $pubs = []): EvidenceRecord => new EvidenceRecord(
            $id, $kind, $data, $file, str_repeat('0', 64), $bytes,
            new \DateTimeImmutable($created), (new \DateTimeImmutable($created))->modify('+14 days'),
            'agent-1', 'var/tmp/' . $file, $note, $pubs,
        );

        return [
            $r('ev-20260930-100000-aaaaaa', '2026-09-30T10:00:00Z', EvidenceKind::Screenshot, EvidenceData::Synthetic, 'cart.png', 300, 'Cart after the fix'),
            $r('ev-20261001-235959-bbbbbb', '2026-10-01T23:59:59Z', EvidenceKind::Trace, EvidenceData::Real, 'orders.json', 1200, 'slow orders route'),
            $r('ev-20261002-000000-cccccc', '2026-10-02T00:00:00Z', EvidenceKind::Screenshot, EvidenceData::Real, 'Inbox.png', 50),
            $r('ev-20261002-120000-dddddd', '2026-10-02T12:00:00Z', EvidenceKind::GraphExport, EvidenceData::Real, 'graph-Orders.html', 9000, 'orders module', [['to' => 'PR acme/shop#7', 'approved_at' => '2026-10-02T13:00:00+00:00', 'approved_by' => 'taras']]),
            $r('ev-20261002-120000-eeeeee', '2026-10-02T12:00:00Z', EvidenceKind::Screenshot, EvidenceData::Synthetic, 'checkout.png', 300),
        ];
    }

    /** @return list<string> */
    private static function ids(array $in): array
    {
        return array_map(static fn (EvidenceRecord $r): string => substr($r->id, -6), EvidenceListQuery::fromInput($in)->apply(self::records())['items']);
    }

    #[Test]
    public function newest_first_by_default_with_a_stable_order_for_ties(): void
    {
        self::assertSame(['eeeeee', 'dddddd', 'cccccc', 'bbbbbb', 'aaaaaa'], self::ids([]));
    }

    #[Test]
    public function every_column_sorts_both_ways(): void
    {
        // 300 bytes twice: a tie keeps newest first, whichever way the column runs.
        self::assertSame(['cccccc', 'eeeeee', 'aaaaaa', 'bbbbbb', 'dddddd'], self::ids(['sort' => 'size', 'dir' => 'asc']));
        self::assertSame(['dddddd', 'bbbbbb', 'eeeeee', 'aaaaaa', 'cccccc'], self::ids(['sort' => 'size', 'dir' => 'desc']));
        // Case-insensitive file names: Inbox.png sorts among the i's.
        self::assertSame(['aaaaaa', 'eeeeee', 'dddddd', 'cccccc', 'bbbbbb'], self::ids(['sort' => 'file', 'dir' => 'asc']));
        self::assertSame(['dddddd', 'eeeeee', 'cccccc', 'aaaaaa', 'bbbbbb'], self::ids(['sort' => 'kind', 'dir' => 'asc']));
        self::assertSame(['dddddd', 'cccccc', 'bbbbbb', 'eeeeee', 'aaaaaa'], self::ids(['sort' => 'data', 'dir' => 'asc']));
        self::assertSame(['aaaaaa', 'bbbbbb', 'cccccc', 'eeeeee', 'dddddd'], self::ids(['sort' => 'expires', 'dir' => 'asc']));
    }

    #[Test]
    public function search_needs_every_word_somewhere_in_the_passport(): void
    {
        self::assertSame(['dddddd', 'bbbbbb'], self::ids(['q' => 'orders']));
        self::assertSame(['bbbbbb'], self::ids(['q' => 'ORDERS slow']));
        self::assertSame(['dddddd'], self::ids(['q' => 'acme/shop#7']), 'where it was published is searchable');
        self::assertSame(['dddddd', 'bbbbbb'], self::ids(['q' => '  orders  ']));
        self::assertSame([], self::ids(['q' => 'orders nothing-like-this']));
    }

    #[Test]
    public function filters_combine(): void
    {
        self::assertSame(['eeeeee', 'cccccc', 'aaaaaa'], self::ids(['kind' => 'screenshot']));
        self::assertSame(['eeeeee', 'aaaaaa'], self::ids(['kind' => 'screenshot', 'data' => 'synthetic']));
        self::assertSame(['dddddd'], self::ids(['visibility' => 'published']));
        self::assertSame(['eeeeee', 'cccccc', 'bbbbbb', 'aaaaaa'], self::ids(['visibility' => 'private']));
    }

    #[Test]
    public function the_date_range_is_whole_utc_days_inclusive(): void
    {
        self::assertSame(['eeeeee', 'dddddd', 'cccccc'], self::ids(['from' => '2026-10-02']));
        self::assertSame(['bbbbbb', 'aaaaaa'], self::ids(['to' => '2026-10-01']), 'the last second of the day is in');
        self::assertSame(['bbbbbb'], self::ids(['from' => '2026-10-01', 'to' => '2026-10-01']));
    }

    #[Test]
    public function kind_counts_ignore_the_kind_filter_but_honour_the_rest(): void
    {
        $page = EvidenceListQuery::fromInput(['kind' => 'trace', 'data' => 'real'])->apply(self::records());

        self::assertSame(['graph-export' => 1, 'screenshot' => 1, 'trace' => 1], $page['kinds']);
        self::assertSame(1, $page['total']);
    }

    #[Test]
    public function pages_split_the_list_and_a_page_past_the_end_is_the_last(): void
    {
        $query = EvidenceListQuery::fromInput(['per' => '10']);
        $records = [];
        for ($i = 0; $i < 23; $i++) {
            $records[] = new EvidenceRecord(sprintf('ev-20261002-1200%02d-abcdef', $i), EvidenceKind::Log, EvidenceData::Synthetic, "l{$i}.log", '', $i,
                new \DateTimeImmutable(sprintf('2026-10-02T12:00:%02dZ', $i)), new \DateTimeImmutable('2026-10-16'), 'cli', '', '');
        }

        $first = $query->apply($records);
        self::assertSame([23, 1, 3, 10], [$first['total'], $first['page'], $first['pages'], count($first['items'])]);

        $last = EvidenceListQuery::fromInput(['per' => '10', 'page' => '3'])->apply($records);
        self::assertCount(3, $last['items']);

        $past = EvidenceListQuery::fromInput(['per' => '10', 'page' => '9'])->apply($records);
        self::assertSame([3, 3], [$past['page'], count($past['items'])]);

        // Every row on exactly one page.
        $seen = [];
        foreach ([1, 2, 3] as $n) {
            foreach (EvidenceListQuery::fromInput(['per' => '10', 'page' => (string) $n, 'sort' => 'kind'])->apply($records)['items'] as $r) {
                $seen[] = $r->id;
            }
        }
        self::assertSame(23, count(array_unique($seen)));
        self::assertSame(23, count($seen));

        $empty = $query->apply([]);
        self::assertSame([0, 1, 1], [$empty['total'], $empty['page'], $empty['pages']]);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function nonsense(): iterable
    {
        yield 'unknown kind' => [['kind' => 'photo'], 'kind must be one of'];
        yield 'unknown data' => [['data' => 'fake'], 'data must be one of'];
        yield 'unknown visibility' => [['visibility' => 'public'], 'visibility must be one of'];
        yield 'not a day' => [['from' => '02.10.2026'], 'from must be a day'];
        yield 'no such day' => [['to' => '2026-02-30'], 'to must be a day'];
        yield 'range backwards' => [['from' => '2026-10-02', 'to' => '2026-10-01'], 'from is after to'];
        yield 'unknown sort' => [['sort' => 'sha256'], 'sort must be one of'];
        yield 'unknown direction' => [['dir' => 'up'], 'dir must be asc or desc'];
        yield 'page zero' => [['page' => '0'], 'page starts at 1'];
        yield 'page not a number' => [['page' => '2abc'], 'page must be a whole number'];
        yield 'per not offered' => [['per' => '1000'], 'per must be one of'];
    }

    /** @param array<string, string> $in */
    #[Test]
    #[DataProvider('nonsense')]
    public function a_value_nobody_can_mean_is_refused_with_a_sentence(array $in, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        EvidenceListQuery::fromInput($in);
    }
}
