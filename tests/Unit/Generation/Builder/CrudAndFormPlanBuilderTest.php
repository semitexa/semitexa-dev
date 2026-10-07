<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation\Builder;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\CrudPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\FormPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\FieldDeclarationWriter;
use Semitexa\Dev\Application\Service\Generation\Support\ModelFieldInference;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Attribute\Version;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldTypes;
use Semitexa\PlatformUi\Domain\Model\Field\Field;
use Semitexa\PlatformUi\Domain\Model\Field\UiField;

/**
 * tk-rs-make-crud: a CRUD screen and a form, generated. The declarations it
 * writes evaluate back to the fields it meant, a model's columns become the
 * fields a person would pick, and the machinery the write engine owns
 * (tenant, version) is never offered as a field.
 */
final class CrudAndFormPlanBuilderTest extends TestCase
{
    #[Test]
    public function a_written_declaration_evaluates_back_to_the_same_field(): void
    {
        $writer = new FieldDeclarationWriter();
        $fields = [
            Field::id(),
            Field::text('title')->required()->set('max', 160)->searchable()->sortable(),
            Field::decimal('price', 3)->sortable(),
            Field::textarea('body'),
            Field::boolean('featured')->filterable(),
            Field::datetime('updatedAt')->readOnly(),
            Field::of('stars', 'text')->label('Rating')->hideOnList(),
        ];
        foreach ($fields as $field) {
            $code = $writer->write($field);
            /** @var UiField $back */
            $back = eval('use Semitexa\PlatformUi\Domain\Model\Field\Field; return ' . $code . ';');
            self::assertEquals(UiFieldTypes::for($field)->formProps($field), UiFieldTypes::for($back)->formProps($back), $code);
            self::assertEquals(UiFieldTypes::for($field)->column($field), UiFieldTypes::for($back)->column($back), $code);
            self::assertSame([$field->sortable, $field->searchable, $field->filterable, $field->onList], [$back->sortable, $back->searchable, $back->filterable, $back->onList], $code);
        }
        self::assertSame('Field::id()', $writer->write(Field::id()));
    }

    #[Test]
    public function a_model_s_columns_become_its_fields_without_the_engine_s_own(): void
    {
        $fields = (new ModelFieldInference())->fields(ResourceModelMetadataRegistry::default()->for(GeneratedInvoiceModel::class));
        $byName = array_combine(array_map(static fn (UiField $f): string => $f->name, $fields), $fields);

        self::assertSame(['id', 'number', 'title', 'contactEmail', 'total', 'paidOn', 'updatedAt'], array_keys($byName), 'tenant and version are the engine\'s; names are camelCase');
        self::assertTrue($byName['title']->searchable && $byName['title']->sortable, 'a column named title is the record\'s name');
        self::assertTrue($byName['number']->searchable && !$byName['number']->sortable);
        self::assertSame('email', $byName['contactEmail']->type);
        self::assertTrue($byName['total']->sortable);
        self::assertTrue($byName['updatedAt']->readOnly);
    }

    #[Test]
    public function make_crud_plans_the_screen_and_its_boot_test(): void
    {
        $plan = (new CrudPlanBuilder(new NameInflector(), new TemplateResolver(), new TemplateRenderer()))->build([
            'module' => 'Billing',
            'name' => 'Category',
            'metadata' => ResourceModelMetadataRegistry::default()->for(GeneratedInvoiceModel::class),
            'nav' => 'Money',
            'dryRun' => true,
        ]);

        self::assertSame([
            'src/modules/Billing/src/Application/Payload/Request/Crud/CategoryCrud.php',
            'src/modules/Billing/tests/Unit/CategoryCrudTest.php',
        ], array_map(static fn ($f): string => $f->path, $plan->files));
        $class = $plan->files[0]->content;
        self::assertStringContainsString("id: 'billing.categories'", $class, 'a regular plural, not "categorys"');
        self::assertStringContainsString("path: '/billing/categories'", $class);
        self::assertStringContainsString("permission: 'categories'", $class);
        self::assertStringContainsString("nav: 'Money'", $class);
        self::assertStringContainsString("Field::text('title')->required()", $class);
        self::assertStringNotContainsString("'tenant", $class);
        self::assertStringContainsString("CrudScreens::get('billing.categories')", $plan->files[1]->content);
    }

    #[Test]
    public function make_form_parses_its_fields_and_plans_the_action_the_partial_and_a_test(): void
    {
        $fields = FormPlanBuilder::parseFields('name:text!, email:email!, note:textarea');
        self::assertSame([['name' => 'name', 'type' => 'text', 'required' => true], ['name' => 'email', 'type' => 'email', 'required' => true], ['name' => 'note', 'type' => 'textarea', 'required' => false]], $fields);

        $plan = (new FormPlanBuilder(new NameInflector(), new TemplateResolver(), new TemplateRenderer()))->build(['module' => 'Shop', 'name' => 'ContactUs', 'fields' => $fields, 'dryRun' => true]);
        self::assertSame([
            'src/modules/Shop/src/Application/Service/Submit/ContactUsFormAction.php',
            'src/modules/Shop/src/Application/View/templates/partials/contact-us-form.html.twig',
            'src/modules/Shop/tests/Unit/ContactUsFormActionTest.php',
        ], array_map(static fn ($f): string => $f->path, $plan->files));
        self::assertStringContainsString("public const NAME = 'shop.contact-us';", $plan->files[0]->content);
        self::assertStringContainsString("Field::email('email')->required(),", $plan->files[0]->content);
        self::assertStringContainsString("ui_form_fields('shop.contact-us')", $plan->files[1]->content);

        $this->expectException(\InvalidArgumentException::class);
        FormPlanBuilder::parseFields('status:choice');
    }
}

#[FromTable(name: 'generated_invoices')]
#[TenantScoped(strategy: 'column', column: 'tenant_id')]
final readonly class GeneratedInvoiceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id = '',
        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $tenant_id = '',
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $number = '',
        #[Column(type: MySqlType::Varchar, length: 160)]
        public string $title = '',
        #[Column(type: MySqlType::Varchar, length: 190, nullable: true)]
        public ?string $contact_email = null,
        #[Column(type: MySqlType::Decimal, precision: 10, scale: 2)]
        public string $total = '0.00',
        #[Column(type: MySqlType::Date, nullable: true)]
        public ?\DateTimeImmutable $paid_on = null,
        #[Version]
        #[Column(type: MySqlType::Int)]
        public int $version = 1,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updated_at = null,
    ) {}
}
