<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldTypes;
use Semitexa\PlatformUi\Domain\Model\Field\UiColumnShape;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * A screen's first field list, read off an ORM model: each column becomes the
 * field its storage suggests (UiFieldTypes::infer — a varchar named email is
 * an email, a text is a textarea, updated_at is read-only), named in
 * camelCase (the CRUD runtime reads the snake_case twin).
 *
 * Left out: relations (a column suggests no options), the tenant column and
 * the #[Version] (the write engine owns both), and the soft-delete marker.
 * The first text field is searched and sorted; numbers and moments sort;
 * switches filter. It is a starting point the developer edits.
 */
final class ModelFieldInference
{
    /** @return list<UiField> */
    public function fields(ResourceModelMetadata $metadata): array
    {
        $skip = array_filter([
            $metadata->tenantColumn()?->propertyName,
            $metadata->versionProperty,
            $metadata->softDelete?->propertyName,
        ]);
        $fields = [];
        // The record's name is what people search and sort by: a column named
        // name / title / label, else the first text column.
        $primary = null;
        foreach (['name', 'title', 'label'] as $candidate) {
            if ($primary === null && $metadata->hasColumn($candidate)) {
                $primary = $candidate;
            }
        }
        $searchable = false;
        foreach ($metadata->columns() as $column) {
            if (in_array($column->propertyName, $skip, true)) {
                continue;
            }
            // The bare storage name ("varchar", "decimal"): what infer() reads.
            $type = $column->type instanceof \BackedEnum ? (string) $column->type->value : $column->type->canonicalName();
            $inferred = UiFieldTypes::infer(new UiColumnShape(
                $column->propertyName,
                strtolower($type),
                $column->nullable,
                $column->length,
                $column->scale,
                $column->isPrimaryKey,
            ));
            $field = self::renamed($inferred, self::camel($column->propertyName));
            $isPrimary = $primary !== null ? $column->propertyName === $primary : !$searchable;
            if ($isPrimary && in_array($field->type, ['text', 'slug', 'email'], true)) {
                $field = $field->searchable()->sortable();
                $searchable = true;
            } elseif (in_array($field->type, ['text', 'slug', 'email'], true)) {
                $field = $field->searchable();
            } elseif (in_array($field->type, ['integer', 'decimal', 'date', 'datetime'], true)) {
                $field = $field->sortable();
            } elseif ($field->type === 'boolean') {
                $field = $field->filterable();
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /** The same field under its camelCase name, label re-derived from it. */
    private static function renamed(UiField $field, string $name): UiField
    {
        if ($name === $field->name) {
            return $field;
        }
        $copy = new UiField($name, $field->type);
        $copy = $field->required ? $copy->required() : $copy;
        $copy = $field->readOnly ? $copy->readOnly() : $copy;
        foreach (['max', 'min', 'scale'] as $setting) {
            if ($field->setting($setting) !== null) {
                $copy = $copy->set($setting, $field->setting($setting));
            }
        }

        return $field->onList ? $copy : $copy->hideOnList();
    }

    private static function camel(string $name): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
    }
}
