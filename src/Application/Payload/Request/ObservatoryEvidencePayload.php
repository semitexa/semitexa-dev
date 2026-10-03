<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * One page of the evidence store for the Observatory's Evidence view: search,
 * kind / data / visibility filters, a created-on date range, a sort and a page.
 *
 * Dev only, like the Graph view: the store holds screenshots and traces of
 * real data. GET only and side-effect free — the hydrator binds query
 * parameters on every method, so a GET that wrote anything would write on a
 * crafted link. Values are kept as sent (bounded) and validated as a whole by
 * EvidenceListQuery, which answers a value nobody can mean with a 400.
 */
#[AsPublicPayload(
    path: '/__observatory/evidence',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryEvidencePayload
{
    /** @var array<string, string> */
    private array $input = [];

    /** @return array<string, string> */
    public function input(): array
    {
        return $this->input;
    }

    public function setQ(mixed $value): void { $this->keep('q', $value, 200); }
    public function setKind(mixed $value): void { $this->keep('kind', $value); }
    public function setData(mixed $value): void { $this->keep('data', $value); }
    public function setVisibility(mixed $value): void { $this->keep('visibility', $value); }
    public function setFrom(mixed $value): void { $this->keep('from', $value); }
    public function setTo(mixed $value): void { $this->keep('to', $value); }
    public function setSort(mixed $value): void { $this->keep('sort', $value); }
    public function setDir(mixed $value): void { $this->keep('dir', $value); }
    public function setPage(mixed $value): void { $this->keep('page', $value); }
    public function setPer(mixed $value): void { $this->keep('per', $value); }

    private function keep(string $name, mixed $value, int $max = 32): void
    {
        $this->input[$name] = is_string($value) || is_int($value) ? mb_substr((string) $value, 0, $max) : '';
    }
}
