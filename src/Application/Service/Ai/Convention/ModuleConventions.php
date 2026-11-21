<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Convention;

use Semitexa\Dev\Application\Service\Generation\Support\GenerationInput;

/**
 * Aggregated conventions for one module.
 *
 * Phase 3 mines `handler_injections` only. `payload_attributes`, `resource_base`,
 * etc. will be filled in by later miners — kept in the schema now so the
 * persisted JSON shape stays stable as miners are added.
 */
final readonly class ModuleConventions
{
    /** The attributes the miner records an injection under ({@see ConventionMiner}). */
    private const INJECTION_ATTRIBUTES = ['InjectAsReadonly', 'InjectAsMutable', 'InjectAsFactory'];
    private const IDENTIFIER = '[A-Za-z_][A-Za-z0-9_]*';
    private const CLASS_NAME = self::IDENTIFIER . '(?:\\\\' . self::IDENTIFIER . ')*';

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

    /**
     * @return array<string, mixed>
     */
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
     * One module's row of the conventions cache, as it was read from disk.
     *
     * The cache is a file, and make:handler writes what it holds into PHP where
     * PHP reads code: a `use` line, an attribute, a property declaration. A
     * `type` of "Acme\X;\nsystem('id');//" rendered a handler that `php -l`
     * accepted and that ran the call when the file was loaded
     * (tk-handler-generator-trusts-the-conventions-cache). So a row is held to
     * what the miner can produce, field by field, and a value outside that is
     * refused by name before anything is generated from it.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \InvalidArgumentException naming the field that is not what it must be
     */
    public static function fromArray(array $data): self
    {
        $module = $data['module'] ?? null;
        if (!is_string($module) || $module === '') {
            throw self::refused('module', $module, 'the module name');
        }

        $rows = $data['handler_injections'] ?? [];
        // A list, as the miner writes it: a map's keys are text of the file's own,
        // and a refusal would quote them back into the terminal.
        if (!is_array($rows) || !array_is_list($rows)) {
            throw self::refused("modules.{$module}.handler_injections", $rows, 'a list of injections');
        }

        $injections = [];
        foreach ($rows as $index => $row) {
            $at = "modules.{$module}.handler_injections[{$index}]";
            if (!is_array($row)) {
                throw self::refused($at, $row, 'an injection: type, short_type, attribute, property and frequency');
            }

            $type = self::field($row, 'type', $at, '/\A\??' . self::CLASS_NAME . '\z/', 'a class name (PHP identifiers joined by "\\"), with "?" before it when nullable');
            $shortType = self::field($row, 'short_type', $at, '/\A' . self::IDENTIFIER . '\z/', 'a PHP identifier');
            $bare = ltrim($type, '?\\');
            $lastSegment = strrpos($bare, '\\') !== false ? substr($bare, strrpos($bare, '\\') + 1) : $bare;
            if ($shortType !== $lastSegment) {
                throw self::refused("{$at}.short_type", $shortType, sprintf('"%s", the last part of its type', $lastSegment));
            }

            $frequency = $row['frequency'] ?? null;
            if (!is_int($frequency) || $frequency < 0) {
                throw self::refused("{$at}.frequency", $frequency, 'a whole number, zero or more');
            }

            $injections[] = new HandlerInjection(
                type: $type,
                shortType: $shortType,
                attribute: self::field($row, 'attribute', $at, '/\A(?:' . implode('|', self::INJECTION_ATTRIBUTES) . ')\z/', 'one of ' . implode(', ', self::INJECTION_ATTRIBUTES)),
                propertyName: self::field($row, 'property', $at, '/\A' . self::IDENTIFIER . '\z/', 'a PHP identifier'),
                frequency: $frequency,
            );
        }

        $payloadAttributes = $data['payload_attributes'] ?? [];
        if (!is_array($payloadAttributes)) {
            throw self::refused("modules.{$module}.payload_attributes", $payloadAttributes, 'a list of names');
        }
        $names = [];
        foreach ($payloadAttributes as $index => $name) {
            if (!is_string($name) || preg_match('/\A\\\\?' . self::CLASS_NAME . '\z/', $name) !== 1) {
                throw self::refused("modules.{$module}.payload_attributes[{$index}]", $name, 'a class name (PHP identifiers joined by "\\")');
            }
            $names[] = $name;
        }

        $resourceBase = $data['resource_base'] ?? null;
        if ($resourceBase !== null && (!is_string($resourceBase) || preg_match('/\A' . self::CLASS_NAME . '\z/', $resourceBase) !== 1)) {
            throw self::refused("modules.{$module}.resource_base", $resourceBase, 'a class name (PHP identifiers joined by "\\"), or null');
        }

        $sampled = $data['handlers_sampled'] ?? 0;
        if (!is_int($sampled) || $sampled < 0) {
            throw self::refused("modules.{$module}.handlers_sampled", $sampled, 'a whole number, zero or more');
        }

        return new self(
            module: $module,
            handler_injections: $injections,
            payload_attributes: $names,
            resource_base: $resourceBase,
            handlers_sampled: $sampled,
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function field(array $row, string $name, string $at, string $pattern, string $mustBe): string
    {
        $value = $row[$name] ?? null;
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw self::refused("{$at}.{$name}", $value, $mustBe);
        }

        return $value;
    }

    private static function refused(string $field, mixed $value, string $mustBe): \InvalidArgumentException
    {
        $shown = is_string($value) ? GenerationInput::shown($value) : get_debug_type($value);

        return new \InvalidArgumentException(sprintf(
            'The conventions cache (%s) is refused: %s is %s, and it must be %s. Rebuild it with `bin/semitexa dev:graph:mine-conventions`.',
            ConventionStore::RELATIVE_PATH,
            $field,
            $shown,
            $mustBe,
        ));
    }
}
