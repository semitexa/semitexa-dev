<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

#[AsPayload(
    path: '{{path}}',
    methods: ['{{method}}'],
    responseWith: {{responseClass}}::class,
)]
{{publicEndpoint}}class {{className}}
{
}
