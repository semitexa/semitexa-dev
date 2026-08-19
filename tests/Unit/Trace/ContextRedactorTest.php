<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ContextRedactor;

/**
 * The redactor is the only gate between real request data and files on disk,
 * so its tests are the security tests of the whole deep-context feature: a
 * secret must die at every nesting level and under every key spelling, and
 * the bounded-size rules must hold no matter what a payload contains.
 */
final class ContextRedactorTest extends TestCase
{
    #[Test]
    public function sensitive_keys_are_masked_at_every_depth_and_spelling(): void
    {
        $out = ContextRedactor::redact([
            'password' => 'p1',
            'Password' => 'p2',
            'api_key' => 'k1',
            'X-Auth-Token' => 't1',
            'dbPassword' => 'p3',
            'user' => [
                'name' => 'taras',
                'credentials' => ['a', 'b'],
                'session_id' => 's1',
            ],
        ]);

        self::assertSame(ContextRedactor::MASK, $out['password']);
        self::assertSame(ContextRedactor::MASK, $out['Password']);
        self::assertSame(ContextRedactor::MASK, $out['api_key']);
        self::assertSame(ContextRedactor::MASK, $out['X-Auth-Token']);
        self::assertSame(ContextRedactor::MASK, $out['dbPassword']);
        self::assertSame(ContextRedactor::MASK, $out['user']['credentials'], 'a sensitive key masks its whole subtree');
        self::assertSame(ContextRedactor::MASK, $out['user']['session_id']);
        self::assertSame('taras', $out['user']['name'], 'benign values survive');
    }

    #[Test]
    public function benign_keys_containing_similar_words_are_not_masked(): void
    {
        $out = ContextRedactor::redact(['author' => 'x', 'category' => 'y']);

        self::assertSame('x', $out['author'], '`author` must not match `authorization`');
        self::assertSame('y', $out['category']);
    }

    #[Test]
    public function strings_are_truncated_and_structures_bounded(): void
    {
        $out = ContextRedactor::redact([
            'long' => str_repeat('a', 500),
            'deep' => ['l2' => ['l3' => ['l4' => ['l5' => 'too deep']]]],
            'wide' => array_fill(0, 40, 'x'),
        ]);

        self::assertSame(201, mb_strlen($out['long']), '200 chars plus the ellipsis');
        self::assertSame('[array:1]', $out['deep']['l2']['l3'], 'depth capped by collapsing, not by dropping');
        self::assertCount(26, $out['wide'], '25 items plus the +more marker');
        self::assertSame('[+15 more]', $out['wide']['…']);
    }

    #[Test]
    public function a_snapshot_reads_initialized_public_state_only(): void
    {
        $dto = new class {
            public string $email = 'a@b.c';
            public string $password = 'hunter2';
            public array $tags = ['x', 'y'];
            public string $uninitialized;
            private string $internal = 'no';

            public function internal(): string
            {
                return $this->internal;
            }
        };

        $snap = ContextRedactor::snapshot($dto);

        self::assertSame('a@b.c', $snap['email']);
        self::assertSame(ContextRedactor::MASK, $snap['password'], 'a secret payload field must not reach disk');
        self::assertSame(['x', 'y'], $snap['tags']);
        self::assertArrayNotHasKey('uninitialized', $snap, 'reading it would fatal on a typed property');
        self::assertArrayNotHasKey('internal', $snap, 'private state is not the payload contract');
    }

    #[Test]
    public function positional_query_bindings_survive_as_bounded_scalars(): void
    {
        $out = ContextRedactor::redact([0 => 'w-123', 1 => 42, 2 => str_repeat('b', 300)]);

        self::assertSame('w-123', $out[0]);
        self::assertSame(42, $out[1]);
        self::assertSame(201, mb_strlen($out[2]));
    }
}
