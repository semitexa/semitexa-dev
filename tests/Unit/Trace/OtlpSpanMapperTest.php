<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\Otlp\OtlpSpanMapper;

final class OtlpSpanMapperTest extends TestCase
{
    private const START = 1_700_000_000_000_000_000;

    #[Test]
    public function a_begin_and_its_end_become_one_span_with_real_timestamps(): void
    {
        $result = OtlpSpanMapper::map([
            'events' => [
                ['type' => 'begin', 'name' => 'request', 'cid' => 1, 'atMs' => 0.0, 'context' => ['path' => '/']],
                ['type' => 'end', 'name' => 'request', 'cid' => 1, 'atMs' => 12.5, 'context' => []],
            ],
        ], 'abc', self::START);

        self::assertCount(1, $result['spans']);
        $span = $result['spans'][0];
        self::assertSame('request', $span['name']);
        self::assertSame((string) self::START, $span['startTimeUnixNano']);
        self::assertSame((string) (self::START + 12_500_000), $span['endTimeUnixNano']);
        self::assertSame([['key' => 'path', 'value' => ['stringValue' => '/']]], $span['attributes']);
    }

    #[Test]
    public function nesting_survives_two_spans_sharing_a_name(): void
    {
        // A re-dispatch through the pipeline opens a second span of the same name
        // inside the first. Closing must match the INNERMOST one, or the two
        // spans swap their durations and the waterfall inverts.
        $result = OtlpSpanMapper::map([
            'events' => [
                ['type' => 'begin', 'name' => 'pipeline', 'cid' => 1, 'atMs' => 0.0, 'context' => []],
                ['type' => 'begin', 'name' => 'pipeline', 'cid' => 1, 'atMs' => 1.0, 'context' => []],
                ['type' => 'end', 'name' => 'pipeline', 'cid' => 1, 'atMs' => 2.0, 'context' => []],
                ['type' => 'end', 'name' => 'pipeline', 'cid' => 1, 'atMs' => 9.0, 'context' => []],
            ],
        ], 'abc', self::START);

        self::assertCount(2, $result['spans']);
        [$inner, $outer] = $result['spans'];

        self::assertSame((string) (self::START + 1_000_000), $inner['startTimeUnixNano']);
        self::assertSame((string) (self::START + 2_000_000), $inner['endTimeUnixNano']);
        self::assertSame($outer['spanId'], $inner['parentSpanId'], 'the inner span hangs off the outer one');
        self::assertSame('', $outer['parentSpanId'], 'the outer span is the root');
    }

    #[Test]
    public function a_span_that_never_closed_is_dropped_and_counted(): void
    {
        // A worker killed mid-request leaves an open span. Giving it the last
        // known moment as an end would turn "we lost the request" into "the
        // request finished", which is a worse answer than saying nothing.
        $result = OtlpSpanMapper::map([
            'events' => [
                ['type' => 'begin', 'name' => 'request', 'cid' => 1, 'atMs' => 0.0, 'context' => []],
                ['type' => 'begin', 'name' => 'handler', 'cid' => 1, 'atMs' => 1.0, 'context' => []],
                ['type' => 'end', 'name' => 'handler', 'cid' => 1, 'atMs' => 4.0, 'context' => []],
            ],
        ], 'abc', self::START);

        self::assertCount(1, $result['spans']);
        self::assertSame('handler', $result['spans'][0]['name']);
        self::assertSame(1, $result['unclosed']);
    }

    #[Test]
    public function a_mark_attaches_to_the_open_span_rather_than_becoming_one(): void
    {
        $result = OtlpSpanMapper::map([
            'events' => [
                ['type' => 'begin', 'name' => 'request', 'cid' => 1, 'atMs' => 0.0, 'context' => []],
                ['type' => 'mark', 'name' => 'cache.miss', 'cid' => 1, 'atMs' => 3.0, 'context' => ['key' => 'k']],
                ['type' => 'end', 'name' => 'request', 'cid' => 1, 'atMs' => 5.0, 'context' => []],
            ],
        ], 'abc', self::START);

        self::assertCount(1, $result['spans']);
        $events = $result['spans'][0]['events'];
        self::assertCount(1, $events);
        self::assertSame('cache.miss', $events[0]['name']);
        self::assertSame((string) (self::START + 3_000_000), $events[0]['timeUnixNano']);
    }

    #[Test]
    public function attributes_keep_their_type_and_nested_values_survive_as_json(): void
    {
        $result = OtlpSpanMapper::map([
            'events' => [
                ['type' => 'begin', 'name' => 'q', 'cid' => 1, 'atMs' => 0.0, 'context' => [
                    'count' => 3,
                    'ms' => 1.5,
                    'cached' => true,
                    'rows' => ['a', 'b'],
                ]],
                ['type' => 'end', 'name' => 'q', 'cid' => 1, 'atMs' => 1.0, 'context' => []],
            ],
        ], 'abc', self::START);

        $byKey = [];
        foreach ($result['spans'][0]['attributes'] as $attribute) {
            $byKey[$attribute['key']] = $attribute['value'];
        }

        self::assertSame(['intValue' => '3'], $byKey['count']);
        self::assertSame(['doubleValue' => 1.5], $byKey['ms']);
        self::assertSame(['boolValue' => true], $byKey['cached']);
        self::assertSame(['stringValue' => '["a","b"]'], $byKey['rows']);
    }
}
