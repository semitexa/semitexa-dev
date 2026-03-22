<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Support;

use Semitexa\Dev\Generation\Data\CapabilityManifest;

final class CapabilityManifestFormatter
{
    public function format(CapabilityManifest $manifest): string
    {
        return json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
