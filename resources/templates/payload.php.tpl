<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

#[AsPayload(
    path: '{{path}}',
    methods: ['{{method}}'],
    responseWith: {{responseClass}}::class,
)]
{{publicEndpoint}}class {{className}} implements ValidatablePayload
{
    public function validate(): PayloadValidationResult
    {
        return new PayloadValidationResult(true, []);
    }
}
