<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

#[{{accessAttribute}}(
    path: '{{path}}',
    methods: ['{{method}}'],
    responseWith: {{responseClass}}::class,
)]
class {{className}}
{
}
