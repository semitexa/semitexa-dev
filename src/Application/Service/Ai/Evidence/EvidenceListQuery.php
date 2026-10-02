<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

/**
 * One page of the evidence store, as the Observatory's Evidence view asks for
 * it: search, filters, a date range, a sort and a page. Validated whole in
 * {@see fromInput()}, so a value nobody can mean is a 400 with a sentence,
 * never a silently different list.
 */
final readonly class EvidenceListQuery
{
    public const SORTS = ['created', 'expires', 'size', 'kind', 'data', 'file'];
    public const PER_PAGE = [10, 25, 50, 100];
    public const VISIBILITY = ['private', 'published'];

    public function __construct(
        public string $q = '',
        public ?EvidenceKind $kind = null,
        public ?EvidenceData $data = null,
        public ?string $visibility = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public string $sort = 'created',
        public string $dir = 'desc',
        public int $page = 1,
        public int $per = 25,
    ) {
    }

    /**
     * @param array<string, string> $in raw query values; '' means "not set"
     * @throws \InvalidArgumentException naming the value that cannot be meant
     */
    public static function fromInput(array $in): self
    {
        $kind = null;
        if (($in['kind'] ?? '') !== '') {
            $kind = EvidenceKind::tryFrom($in['kind']) ?? throw new \InvalidArgumentException('kind must be one of: ' . implode(', ', EvidenceKind::values()));
        }
        $data = null;
        if (($in['data'] ?? '') !== '') {
            $data = EvidenceData::tryFrom($in['data']) ?? throw new \InvalidArgumentException('data must be one of: ' . implode(', ', EvidenceData::values()));
        }
        $visibility = ($in['visibility'] ?? '') === '' ? null : $in['visibility'];
        if ($visibility !== null && !in_array($visibility, self::VISIBILITY, true)) {
            throw new \InvalidArgumentException('visibility must be one of: ' . implode(', ', self::VISIBILITY));
        }
        $from = self::day($in['from'] ?? '', 'from');
        $to = self::day($in['to'] ?? '', 'to');
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('from is after to');
        }
        $sort = ($in['sort'] ?? '') === '' ? 'created' : $in['sort'];
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException('sort must be one of: ' . implode(', ', self::SORTS));
        }
        $dir = ($in['dir'] ?? '') === '' ? 'desc' : $in['dir'];
        if ($dir !== 'asc' && $dir !== 'desc') {
            throw new \InvalidArgumentException('dir must be asc or desc');
        }
        $page = self::whole($in['page'] ?? '', 'page', 1);
        if ($page < 1) {
            throw new \InvalidArgumentException('page starts at 1');
        }
        $per = self::whole($in['per'] ?? '', 'per', 25);
        if (!in_array($per, self::PER_PAGE, true)) {
            throw new \InvalidArgumentException('per must be one of: ' . implode(', ', self::PER_PAGE));
        }

        return new self(trim(mb_substr($in['q'] ?? '', 0, 200)), $kind, $data, $visibility, $from, $to, $sort, $dir, $page, $per);
    }

    /**
     * @param list<EvidenceRecord> $records
     * @return array{items: list<EvidenceRecord>, total: int, page: int, pages: int, kinds: array<string, int>}
     *         kinds counts what the search and the OTHER filters leave, so the
     *         kind menu says how many each choice would show
     */
    public function apply(array $records): array
    {
        $matching = array_values(array_filter($records, fn (EvidenceRecord $r): bool => $this->matches($r, true)));
        $kinds = [];
        foreach ($matching as $r) {
            $kinds[$r->kind->value] = ($kinds[$r->kind->value] ?? 0) + 1;
        }
        ksort($kinds);

        $rows = array_values(array_filter($matching, fn (EvidenceRecord $r): bool => $this->kind === null || $r->kind === $this->kind));
        usort($rows, fn (EvidenceRecord $a, EvidenceRecord $b): int => $this->compare($a, $b));

        $total = count($rows);
        $pages = max(1, (int) ceil($total / $this->per));
        // Past the end (the list shrank under a filter): the last page, said so in `page`.
        $page = min($this->page, $pages);

        return [
            'items' => array_slice($rows, ($page - 1) * $this->per, $this->per),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'kinds' => $kinds,
        ];
    }

    private function matches(EvidenceRecord $r, bool $ignoreKind): bool
    {
        if (!$ignoreKind && $this->kind !== null && $r->kind !== $this->kind) {
            return false;
        }
        if ($this->data !== null && $r->data !== $this->data) {
            return false;
        }
        if ($this->visibility !== null && ($r->publications === [] ? 'private' : 'published') !== $this->visibility) {
            return false;
        }
        // Days in UTC, both ends inclusive: to=2026-10-02 keeps the whole day.
        if ($this->from !== null && $r->createdAt < $this->from) {
            return false;
        }
        if ($this->to !== null && $r->createdAt >= $this->to->modify('+1 day')) {
            return false;
        }
        if ($this->q === '') {
            return true;
        }
        $haystack = mb_strtolower(implode("\n", [$r->id, $r->file, $r->note, $r->source, $r->createdBy, $r->kind->value, $r->data->value, ...array_column($r->publications, 'to')]));
        foreach (preg_split('/\s+/', mb_strtolower($this->q)) ?: [] as $word) {
            if ($word !== '' && !str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    private function compare(EvidenceRecord $a, EvidenceRecord $b): int
    {
        $key = static fn (EvidenceRecord $r, string $sort): mixed => match ($sort) {
            'expires' => $r->expiresAt,
            'size' => $r->bytes,
            'kind' => $r->kind->value,
            'data' => $r->data->value,
            'file' => mb_strtolower($r->file),
            default => $r->createdAt,
        };
        $order = $key($a, $this->sort) <=> $key($b, $this->sort);
        // Ties keep a stable, newest-first order whichever way the column runs,
        // so paging never shows a row twice or skips one.
        if ($order === 0) {
            $order = [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id];

            return $order;
        }

        return $this->dir === 'asc' ? $order : -$order;
    }

    private static function day(string $value, string $name): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($name . ' must be a day as YYYY-MM-DD');
        }

        return $day;
    }

    private static function whole(string $value, string $name, int $default): int
    {
        if ($value === '') {
            return $default;
        }
        if (preg_match('/^\d{1,6}$/', $value) !== 1) {
            throw new \InvalidArgumentException($name . ' must be a whole number');
        }

        return (int) $value;
    }
}
