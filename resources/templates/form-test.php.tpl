<?php

declare(strict_types=1);

namespace {{namespace}};

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use {{actionClass}};
use Semitexa\PlatformUi\Application\Service\Field\UiFieldSet;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;

/** The "{{title}}" form: its fields are valid declarations and a filled form is accepted. */
final class {{className}} extends TestCase
{
    #[Test]
    public function a_filled_form_is_accepted(): void
    {
        $action = new {{actionShort}}();
        $values = (new UiFieldSet($action->fields()))->cast({{sample}});
        self::assertSame({{fieldNames}}, array_keys($values));

        $result = $action->handle(new UiFormSubmitActionContext('uci_form_test_0001', {{actionShort}}::NAME, 'ui_evt_x', {{sample}}, [], UiFormSubmitResult::fromFieldResults([])));

        self::assertTrue($result->accepted, $result->message);
    }
}
