<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

#[AsResource(handle: '{{handle}}', template: '{{template}}')]
class {{className}} extends HtmlResponse implements ResourceInterface
{
}
