<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Explorer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Handler\PayloadHandler\ExplorerSandboxHandler;
use Semitexa\Dev\Application\Service\Trace\LiveRequestInput;
use Semitexa\Dev\Application\Service\Trace\ReplayRunner;

final class SandboxTest extends TestCase
{
    #[Test]
    public function the_envelope_tells_an_empty_input_from_a_request_never_hydrated(): void
    {
        $root = ['type' => 'begin', 'name' => 'request', 'context' => ['path' => '/items', 'method' => 'post']];
        $hydrated = ReplayRunner::envelopeOf(['events' => [
            $root,
            ['type' => 'end', 'name' => 'payload.hydrate_and_validate', 'context' => ['snapshot' => []]],
        ]]);
        $refused = ReplayRunner::envelopeOf(['events' => [$root]]);

        self::assertTrue($hydrated['hydrated']);
        self::assertSame([], $hydrated['payload']);
        self::assertSame('POST', $hydrated['method']);
        self::assertFalse($refused['hydrated'], 'an auth refusal never reached hydration');
    }

    #[Test]
    public function the_envelope_prefers_the_raw_input_the_setters_can_take_back(): void
    {
        $envelope = ReplayRunner::envelopeOf(['events' => [
            ['type' => 'begin', 'name' => 'request', 'context' => ['path' => '/customers', 'method' => 'GET']],
            ['type' => 'end', 'name' => 'payload.hydrate_and_validate', 'context' => [
                'snapshot' => ['rawPerPage' => '3'],
                'input' => ['perPage' => '3'],
            ]],
        ]]);

        self::assertSame(['perPage' => '3'], $envelope['payload'], 'the payload stores it as $rawPerPage; only the input name reaches setPerPage()');
    }

    #[Test]
    public function live_input_merges_like_the_hydrator_body_first_and_drops_the_trace_marker(): void
    {
        self::assertSame(
            ['name' => 'from-body', 'page' => '2'],
            LiveRequestInput::merge(['name' => 'from-query', 'page' => '2', '__trace' => '1'], ['name' => 'from-body']),
        );
    }

    #[Test]
    public function the_envelope_replays_the_concrete_path_not_the_route_pattern(): void
    {
        $envelope = ReplayRunner::envelopeOf(['events' => [
            ['type' => 'begin', 'name' => 'request', 'context' => ['path' => '/items/{id}', 'method' => 'GET']],
            ['type' => 'end', 'name' => 'payload.hydrate_and_validate', 'context' => ['input' => [], 'request_path' => '/items/7']],
        ]]);
        $older = ReplayRunner::envelopeOf(['events' => [
            ['type' => 'begin', 'name' => 'request', 'context' => ['path' => '/items/{id}', 'method' => 'GET']],
        ]]);

        self::assertSame('/items/7', $envelope['path'], 'the pattern carries no value for {id}');
        self::assertSame('/items/{id}', $older['path'], 'a trace from before request_path still resolves its route');
    }

    #[Test]
    public function path_params_are_read_off_the_path_like_the_hydrator_reads_them(): void
    {
        self::assertSame(['id' => '7'], ReplayRunner::pathParams('/items/{id}', [], '/items/7'));
        self::assertSame(
            ['id' => '42', 'slug' => 'a/b'],
            ReplayRunner::pathParams('/items/{id}/tags/{slug}', ['id' => '\\d+'], '/items/42/tags/a%2Fb'),
            'an encoded slash is decoded after matching, never used to split segments',
        );
        self::assertSame([], ReplayRunner::pathParams('/items/{id}', ['id' => '\\d+'], '/items/x'), 'a requirement that fails matches nothing');
        self::assertSame([], ReplayRunner::pathParams('/about', [], '/about'));
    }

    #[Test]
    public function the_child_answer_is_its_last_json_object_line(): void
    {
        $output = "PHP Warning: something noisy\n{\"artifact\":\"x\",\"verdict\":\"ok\"}\n";

        self::assertSame(['artifact' => 'x', 'verdict' => 'ok'], ExplorerSandboxHandler::lastJsonObject($output));
        self::assertNull(ExplorerSandboxHandler::lastJsonObject("Fatal error: boom\n[1,2]"), 'a JSON list is not an envelope');
        self::assertNull(ExplorerSandboxHandler::lastJsonObject(''));
    }
}
