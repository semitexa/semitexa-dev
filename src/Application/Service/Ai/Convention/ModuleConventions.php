<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Convention;

/**
 * Aggregated conventions for one module.
 *
 * Phase 3 mines `handler_injections` only. `payload_attributes`, `resource_base`,
 * etc. will be filled in by later miners — kept in the schema now so the
 * persisted JSON shape stays stable as miners are added.
 */
final readonly class ModuleConventions
{
    /**
     * @param list<HandlerInjection> $handler_injections
     * @param list<string>           $payload_attributes Reserved for future miner.
     * @param ?string                $resource_base      Reserved for future miner.
     * @param int                    $handlers_sampled   Number of handler classes scanned to derive the above.
     */
    public function __construct(
        public string $module,
        public array $handler_injections = [],
        public array $payload_attributes = [],
        public ?string $resource_base = null,
        public int $handlers_sampled = 0,
    ) {}

    /**
     * @return list<HandlerInjection>
     */
    public function recurringHandlerInjections(int $minFrequency = 2): array
    {
        return array_values(array_filter(
            $this->handler_injections,
            static fn(HandlerInjection $hi): bool => $hi->frequency >= $minFrequency,
        ));
    }

    public function toArray(): array
    {
        return [
            'module'             => $this->module,
            'handlers_sampled'   => $this->handlers_sampled,
            'handler_injections' => array_map(static fn(HandlerInjection $hi): array => [
                'type'         => $hi->type,
                'short_type'   => $hi->shortType,
                'attribute'    => $hi->attribute,
                'property'     => $hi->propertyName,
                'frequency'    => $hi->frequency,
            ], $this->handler_injections),
            'payload_attributes' => $this->payload_attributes,
            'resource_base'      => $this->resource_base,
        ];
    }

    /**
     * @param array{module: string, handlers_sampled?: int, handler_injections?: list<array{type: string, short_type: string, attribute: string, property: string, frequency: int}>, payload_attributes?: list<string>, resource_base?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        $injections = [];
        foreach ($data['handler_injections'] ?? [] as $row) {
            $injections[] = new HandlerInjection(
                type: $row['type'],
                shortType: $row['short_type'],
                attribute: $row['attribute'],
                propertyName: $row['property'],
                frequency: $row['frequency'],
            );
        }
        return new self(
            module: $data['module'],
            handler_injections: $injections,
            payload_attributes: $data['payload_attributes'] ?? [],
            resource_base: $data['resource_base'] ?? null,
            handlers_sampled: $data['handlers_sampled'] ?? 0,
        );
    }
}
