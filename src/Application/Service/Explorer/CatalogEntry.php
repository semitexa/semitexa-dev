<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Explorer;

/**
 * One route as the Explorer lists it: enough to group, search and label it
 * without assembling its contract, which is fetched only when it is opened.
 */
final readonly class CatalogEntry
{
    /**
     * @param list<string> $methods     callable methods; the implicit OPTIONS/HEAD left out
     * @param list<string> $pathParams  `{name}` placeholders, in path order
     * @param list<string> $profiles    declared render profiles, empty for legacy routes
     * @param array<string, string> $requirements path-param name → regex the router enforces
     */
    public function __construct(
        public string $id,
        public RouteKind $kind,
        public string $path,
        public array $methods,
        public ?string $name,
        public string $module,
        public string $access,
        public string $payload,
        public ?string $handler,
        public ?string $response,
        public ?string $transport,
        public array $profiles,
        public array $pathParams,
        public array $requirements = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'path' => $this->path,
            'methods' => $this->methods,
            'name' => $this->name,
            'module' => $this->module,
            'access' => $this->access,
            'payload' => $this->payload,
            'handler' => $this->handler,
            'response' => $this->response,
            'transport' => $this->transport,
            'profiles' => $this->profiles,
            'path_params' => $this->pathParams,
            'requirements' => (object) $this->requirements,
        ];
    }
}
