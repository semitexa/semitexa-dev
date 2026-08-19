<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Queue\QueueTransportInterface;

/**
 * The replay's queue stub: everything a handler tries to publish is captured
 * and reported instead of delivered. Combined with QueueTransportRegistry
 * being reset first, this is fail-closed — a transport name the replay did
 * not stub throws on create() rather than reaching a real broker.
 */
final class CapturingQueueTransport implements QueueTransportInterface
{
    /** @var list<array{queue: string, payload: string}> */
    private array $published = [];

    public function publish(string $queueName, string $payload): void
    {
        $this->published[] = ['queue' => $queueName, 'payload' => $payload];
    }

    public function consume(string $queueName, callable $callback): void
    {
        // A replay never consumes; a handler that tries is a finding in itself.
        throw new \LogicException('consume() is not available inside a replay sandbox.');
    }

    /** @return list<array{queue: string, payload: string}> */
    public function drain(): array
    {
        $out = $this->published;
        $this->published = [];

        return $out;
    }
}
