<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Payload\Request\ExplorerSandboxPayload;
use Semitexa\Dev\Application\Service\Ai\Verify\ShellProcessRunner;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * Runs the Explorer's sandbox call in a PROCESS OF ITS OWN.
 *
 * ReplayRunner arms process-global state for the duration of a run — the
 * queue transport registry swapped for a captor, SandboxGuard raised so mail
 * is withheld. Inside this worker that would capture the queue publishes and
 * swallow the mail of every other request it is serving at the same moment.
 * `ai:observe sandbox` in a child process has nobody else to affect.
 */
#[AsPayloadHandler(payload: ExplorerSandboxPayload::class, resource: ResourceResponse::class)]
final class ExplorerSandboxHandler implements TypedHandlerInterface
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const TIMEOUT_SECONDS = 30.0;

    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ExplorerSandboxPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $path = $payload->getPath();
        if (!in_array($payload->getMethod(), self::METHODS, true) || !str_starts_with($path, '/') || str_starts_with($path, '/__')) {
            return $this->json($resource, ['error' => 'bad-target', 'detail' => 'method must be one of ' . implode(', ', self::METHODS) . '; path must be an app path'], HttpStatus::UnprocessableEntity->value);
        }

        $root = ProjectRoot::get();
        $run = (new ShellProcessRunner(self::TIMEOUT_SECONDS))->run([
            PHP_BINARY,
            $root . '/vendor/bin/semitexa',
            'ai:observe',
            'sandbox',
            '--method=' . $payload->getMethod(),
            '--path=' . $path,
            '--input=' . json_encode((object) $payload->getInput(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], $root);

        $result = self::lastJsonObject($run['output']);
        if ($result === null) {
            return $this->json($resource, [
                'error' => $run['failure'] ?? 'no-envelope',
                'detail' => 'ai:observe sandbox did not answer with JSON (exit ' . $run['exit'] . ')',
                'output' => mb_substr($run['output'], -2000),
            ], HttpStatus::BadGateway->value);
        }

        return $this->json($resource, $result);
    }

    /**
     * The command's envelope is its last JSON line; anything a bootstrap
     * printed ahead of it is not part of the answer.
     *
     * @return array<string, mixed>|null
     */
    public static function lastJsonObject(string $output): ?array
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $decoded = json_decode($lines[$i], true);
            if (is_array($decoded) && !array_is_list($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private function json(ResourceResponse $resource, array $body, int $status = 200): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
