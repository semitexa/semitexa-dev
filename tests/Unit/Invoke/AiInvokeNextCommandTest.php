<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Invoke;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiInvokeCommand;

/**
 * The envelope suggests what to run next, and an agent runs what it is handed.
 *
 * A suggestion without `--handler` or `--route` dies on the required-target
 * check before it previews anything, and one without `--payload` silently runs
 * `{}` instead of the input under discussion — both are worse than no
 * suggestion, because they read as the next step and are not.
 *
 * The payload is the caller's own, never the redacted copy. Which means it must
 * not be repeated when the redactor masked any of it: this envelope gets piped
 * and pasted, and a masked `payload_input` beside a verbatim `--payload=` in the
 * same document would hand back exactly what the masking removed.
 */
final class AiInvokeNextCommandTest extends TestCase
{
    /** @param array<string, mixed> $envelope */
    private function nextCommands(array $envelope, ?string $payloadJson): array
    {
        $command = new AiInvokeCommand();

        $payload = new \ReflectionProperty($command, 'invokedPayloadJson');
        $payload->setValue($command, $payloadJson);

        $build = new \ReflectionMethod($command, 'buildNextCommands');

        return $build->invoke($command, $envelope);
    }

    /** @return list<string> */
    private function argsOfFirst(array $envelope, ?string $payloadJson): array
    {
        $next = $this->nextCommands($envelope, $payloadJson);
        self::assertNotSame([], $next, 'this verdict is supposed to suggest something');

        return $next[0]['args'];
    }

    #[Test]
    public function a_preview_suggestion_names_the_route_it_previewed(): void
    {
        $args = $this->argsOfFirst([
            'verdict' => 'preview',
            'target' => ['handler_class' => 'App\\Handler\\Show', 'route_path' => '/items/7', 'method' => 'GET'],
        ], '{}');

        self::assertContains('--route=/items/7', $args);
        self::assertNotContains('--handler=App\\Handler\\Show', $args, 'the two options are mutually exclusive');
    }

    #[Test]
    public function a_non_get_method_is_carried_or_the_route_resolves_to_a_different_handler(): void
    {
        $args = $this->argsOfFirst([
            'verdict' => 'preview',
            'target' => ['handler_class' => 'App\\Handler\\Store', 'route_path' => '/items', 'method' => 'POST'],
        ], '{}');

        self::assertContains('--method=POST', $args);
    }

    #[Test]
    public function a_handler_target_is_named_by_class(): void
    {
        $args = $this->argsOfFirst([
            'verdict' => 'preview',
            'target' => ['handler_class' => 'App\\Handler\\Show', 'route_path' => null, 'method' => null],
        ], '{}');

        self::assertContains('--handler=App\\Handler\\Show', $args);
    }

    #[Test]
    public function a_refusal_suggests_a_preview_of_the_same_target(): void
    {
        $args = $this->argsOfFirst([
            'verdict' => 'refused',
            'target' => ['handler_class' => 'App\\Handler\\Show', 'route_path' => null, 'method' => null],
        ], '{"id":7}');

        self::assertContains('--handler=App\\Handler\\Show', $args);
        self::assertContains('--preview', $args);
        self::assertContains('--payload={"id":7}', $args, 'previewing a different input answers a different question');
    }

    #[Test]
    public function the_payload_under_discussion_is_carried_into_the_run(): void
    {
        $args = $this->argsOfFirst([
            'verdict' => 'preview',
            'target' => ['handler_class' => 'App\\Handler\\Store', 'route_path' => '/items', 'method' => 'POST'],
        ], '{"title":"a thing"}');

        self::assertContains('--payload={"title":"a thing"}', $args);
    }

    #[Test]
    public function an_empty_payload_is_left_out_rather_than_spelled_as_an_empty_object(): void
    {
        $args = $this->argsOfFirst([
            'verdict' => 'preview',
            'target' => ['handler_class' => 'App\\Handler\\Show', 'route_path' => null, 'method' => null],
        ], '{}');

        self::assertSame([], array_filter($args, static fn (string $a) => str_starts_with($a, '--payload')));
    }

    #[Test]
    public function a_payload_the_redactor_masks_is_not_repeated_back(): void
    {
        $next = $this->nextCommands([
            'verdict' => 'preview',
            'target' => ['handler_class' => 'App\\Handler\\Login', 'route_path' => null, 'method' => null],
        ], '{"user":"ann","password":"hunter2"}');

        $flattened = json_encode($next);

        self::assertStringNotContainsString('hunter2', (string) $flattened, 'the envelope masks this one field away');
        self::assertStringContainsString('--payload', $next[0]['why'], 'and says why the caller has to pass it again');
    }

    /**
     * The suggestion still has to be runnable when the payload is withheld —
     * the target is the part that cannot be guessed from the conversation.
     */
    #[Test]
    public function a_withheld_payload_does_not_cost_the_suggestion_its_target(): void
    {
        $args = $this->argsOfFirst([
            'verdict' => 'preview',
            'target' => ['handler_class' => 'App\\Handler\\Login', 'route_path' => null, 'method' => null],
        ], '{"password":"hunter2"}');

        self::assertContains('--handler=App\\Handler\\Login', $args);
    }
}
