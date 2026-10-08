<?php

declare(strict_types=1);

namespace {{namespace}};

use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Attribute\AsCrud;
use {{modelClass}};
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/**
 * The {{plural}} screen: list, live grid, view / create / edit dialogs,
 * delete, navigation and Ctrl+K. Permissions: {{permissionNote}}.
 *
 * The fields were read off {{modelShort}}; edit them freely — a choice's
 * options (Field::choice('status', [...])), labels, help, which fields are
 * searchable, sortable or filterable. Own actions go in actions().
 */
#[AsCrud(
{{attributeArgs}}
)]
final class {{className}} extends CrudDefinition
{
    public function fields(): array
    {
        return [
{{fields}}
        ];
    }
}
