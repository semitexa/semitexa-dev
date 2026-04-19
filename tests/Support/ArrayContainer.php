<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Support;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Minimal PSR-11 container for tests: hold a fixed map of class-name → instance.
 *
 * Used to exercise {@see \Semitexa\Core\Container\PropertyInjector::inject()}
 * without spinning up the full SemitexaContainer build pipeline — good enough
 * for proving that #[InjectAsReadonly] property injection wires a command or
 * service from a known graph.
 */
final class ArrayContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $services;

    /**
     * @param array<string, object> $services keyed by class-name
     */
    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    public function set(string $id, object $instance): void
    {
        $this->services[$id] = $instance;
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new class("no binding for '{$id}'") extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
