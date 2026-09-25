<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Explorer;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Dev\Application\Service\Trace\ReplayRunner;
use Semitexa\Dev\Application\Service\Trace\TraceReader;

/**
 * Inputs this route has actually been called with, from the recorded traces:
 * the Explorer's "recorded" variations.
 *
 * Only TRACED requests carry a payload snapshot — every Explorer call, every
 * page opened with `?__trace=1`, every sandbox run. Snapshots are REDACTED at
 * recording time, so a secret comes back as the mask, never as the value.
 * A trace belongs to the route when its path and method resolve to the same
 * payload class — a short route name is not unique across modules.
 */
#[AsService]
final class RecordedInputs
{
    private const SCAN_NEWEST = 80;

    #[InjectAsReadonly]
    protected TraceReader $traces;

    #[InjectAsReadonly]
    protected AttributeDiscovery $discovery;

    /**
     * @return list<array{file: string, recordedAt: string, method: string, path: string, status: int|null, input: array<string, mixed>}>
     */
    public function forRoute(CatalogEntry $entry, int $limit = 5): array
    {
        $found = [];
        $seen = [];
        foreach ($this->traces->list(self::SCAN_NEWEST) as $row) {
            $method = strtoupper($row['method']);
            if (!in_array($method, $entry->methods, true) || $row['path'] === '' || $row['path'] === '—') {
                continue;
            }
            $route = $this->discovery->findRoute($row['path'], $method);
            if (!is_array($route) || ($route['class'] ?? null) !== $entry->payload) {
                continue;
            }

            $raw = $this->raw($row['file']);
            $envelope = $raw !== null ? ReplayRunner::envelopeOf($raw) : ['error' => 'unreadable'];
            // Stopped before hydration (an auth refusal): nothing to offer. Rejected
            // AT hydration (400/422): the snapshot is of the payload object, which
            // never received the rejected values — replaying it is not that call.
            if (isset($envelope['error']) || !$envelope['hydrated'] || in_array($row['status'], [400, 422], true)) {
                continue;
            }
            $input = $envelope['payload'];
            $key = $method . ' ' . $envelope['path'] . ' ' . json_encode($input);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $found[] = [
                'file' => $row['file'],
                'recordedAt' => $row['recordedAt'],
                'method' => $method,
                // The concrete path the call used; the trace list shows the pattern.
                'path' => $envelope['path'],
                'status' => $row['status'],
                'input' => $input,
            ];
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /** @return array<string, mixed>|null */
    private function raw(string $file): ?array
    {
        $path = $this->traces->dir() . '/' . basename($file);
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) && isset($decoded['events']) ? $decoded : null;
    }
}
