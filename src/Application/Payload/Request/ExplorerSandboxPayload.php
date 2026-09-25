<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * `POST /__explorer/sandbox` — run one request through the replay sandbox:
 * `{"method": "POST", "path": "/items/7", "input": {...}}`. Writes roll back,
 * queue handoffs are captured, mail is withheld.
 *
 * Public for the same reason `/__observatory` is: the route only exists where
 * semitexa/dev is installed, and the handler answers 404 to anyone the
 * ObservatoryPanelGate refuses. Not CSRF-exempt: it executes handlers.
 */
#[AsPublicPayload(
    path: '/__explorer/sandbox',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
)]
final class ExplorerSandboxPayload
{
    private string $method = 'GET';
    private string $path = '';

    /** @var array<string, mixed> */
    private array $input = [];

    public function setMethod(string $method): void
    {
        $this->method = strtoupper(trim($method));
    }

    public function setPath(string $path): void
    {
        $this->path = trim($path);
    }

    /** @param array<string, mixed> $input */
    public function setInput(array $input): void
    {
        $this->input = $input;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function getInput(): array
    {
        return $this->input;
    }
}
