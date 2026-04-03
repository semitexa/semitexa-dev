<?php

declare(strict_types=1);

namespace Semitexa\Dev\RemoteDeployment\Data;

final readonly class RemoteOsInfo
{
    public function __construct(
        public string $id,
        public string $versionId,
        public ?string $prettyName,
    ) {}
}
