<?php

declare(strict_types=1);

namespace {{namespace}};

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormFieldsInterface;
use Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface;
use Semitexa\PlatformUi\Attribute\AsFormSubmitAction;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionResult;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

/**
 * The "{{title}}" form. Its fields are declared once, here: the partial
 * renders them (ui_form_fields('{{actionName}}')), the server checks their
 * rules before handle() runs, and handle() casts the values with the same list.
 */
#[AsService]
#[ExecutionScoped]
#[AsFormSubmitAction(self::NAME)]
final class {{className}} implements UiFormSubmitActionInterface, UiFormFieldsInterface
{
    public const NAME = '{{actionName}}';

    public function name(): string
    {
        return self::NAME;
    }

    public function fields(): array
    {
        return [
{{fields}}
        ];
    }

    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult
    {
        // Each field, cast to what is stored (a date as Y-m-d, a moment in UTC…).
        $values = (new UiFieldSet($this->fields()))->cast($context->values);

        // Do the work with $values here. Answer a problem with a field as
        // UiFormSubmitActionResult::rejected('…')->withFieldErrors(['field' => '…']).

        return UiFormSubmitActionResult::accepted('{{doneMessage}}')->resettingForm();
    }
}
