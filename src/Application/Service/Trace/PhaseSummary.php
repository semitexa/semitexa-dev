<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * Folds one trace buffer into the handful of numbers the live panel needs to
 * draw a request through the pipeline: milliseconds per architectural stage,
 * the query count, and how the request ended.
 *
 * Lives on the journal END line (key `phases`), which is what makes the
 * panel's animation honest — the particle dwells at each node for the share
 * of time that stage really took, and the node's rolling average is the sum
 * of real requests, not a guess. The keys are short because the journal line
 * is an atomic write with a byte cap; this is a summary, the waterfall is
 * the detail.
 */
final class PhaseSummary
{
    /** Span name → phase key. Anything else is somebody's nested work. */
    private const STAGES = [
        'auth.pre_hydration_gate' => 'gate',
        'payload.hydrate_and_validate' => 'hydrate',
        'resource.resolve' => 'resolve',
        'pipeline.auth_check' => 'auth',
        'pipeline.listener' => 'listeners',
        'pipeline.handler' => 'handler',
        'pipeline.handler_completed' => 'completed',
        'response.render' => 'render',
    ];

    /**
     * @param  list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public static function fold(array $events): array
    {
        $ms = [];
        $listeners = 0;
        $queries = 0;
        $queryMs = 0.0;
        $outcome = 'ok';
        $detail = null;
        $handler = null;
        $queued = 0;

        foreach ($events as $event) {
            $type = $event['type'] ?? null;
            $name = (string) ($event['name'] ?? '');

            if ($type === 'query') {
                $queries++;
                $queryMs += (float) ($event['durationMs'] ?? 0.0);
                continue;
            }

            if ($type === 'end' && isset(self::STAGES[$name])) {
                $key = self::STAGES[$name];
                $ms[$key] = ($ms[$key] ?? 0.0) + (float) ($event['durationMs'] ?? 0.0);
                if ($key === 'listeners') {
                    $listeners++;
                }
                continue;
            }

            if ($type === 'begin' && $name === 'pipeline.handler') {
                $class = $event['context']['handler'] ?? null;
                $handler = is_string($class) ? self::short($class) : null;
                continue;
            }

            if ($type === 'mark') {
                if ($name === 'request.exception') {
                    $outcome = 'exception';
                    $class = $event['context']['class'] ?? null;
                    $detail = is_string($class) ? self::short($class) : null;
                } elseif ($name === 'request.short_circuit' && $outcome === 'ok') {
                    $outcome = 'rejected';
                    $reason = $event['context']['reason'] ?? null;
                    $detail = is_string($reason) ? $reason : null;
                } elseif ($name === 'pipeline.handler.queued') {
                    $queued++;
                    if ($outcome === 'ok') {
                        $outcome = 'queued';
                    }
                } elseif ($name === 'event.listener.queued') {
                    $queued++;
                }
            }
        }

        if ($ms === [] && $queries === 0 && $handler === null) {
            return [];
        }

        $out = [];
        foreach (self::STAGES as $key) {
            if (isset($ms[$key])) {
                $out[$key] = round($ms[$key], 3);
            }
        }
        if ($listeners > 0) {
            $out['n'] = $listeners;
        }
        if ($queries > 0) {
            $out['q'] = $queries;
            $out['qms'] = round($queryMs, 3);
        }
        if ($handler !== null) {
            $out['by'] = $handler;
        }
        if ($queued > 0) {
            // Work this request handed to the queue; the panel draws it as an
            // impulse leaving the pipeline for the jobs lane.
            $out['queued'] = $queued;
        }
        $out['outcome'] = $outcome;
        if ($detail !== null) {
            $out['detail'] = $detail;
        }

        return $out;
    }

    private static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
