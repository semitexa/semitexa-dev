<?php

declare(strict_types=1);

namespace {{namespace}};

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Crud\Application\Service\Screen\CrudRecords;
use Semitexa\Crud\Application\Service\Screen\CrudScreens;
use {{crudClass}};
use {{modelClass}};
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;

/**
 * The {{plural}} screen boots (its id is unique, its actions have code behind
 * them), every field maps to a column of {{modelShort}}, and the fields make a
 * grid contract.
 */
final class {{className}} extends TestCase
{
    protected function tearDown(): void
    {
        CrudScreens::reset();
    }

    #[Test]
    public function the_screen_boots_and_every_field_is_a_column(): void
    {
        CrudScreens::discover([{{crudShort}}::class]);
        $screen = CrudScreens::get('{{screenId}}');
        self::assertInstanceOf({{crudShort}}::class, $screen);

        $metadata = ResourceModelMetadataRegistry::default()->for({{modelShort}}::class);
        foreach ($screen->fields() as $field) {
            self::assertTrue($metadata->hasColumn(CrudRecords::property($field, $metadata)), $field->name);
        }
        self::assertNotSame([], (new UiFieldSet($screen->fields()))->contractUi()['columns']);
    }
}
