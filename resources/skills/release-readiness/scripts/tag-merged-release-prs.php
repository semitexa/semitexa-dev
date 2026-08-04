#!/usr/bin/env php
<?php

declare(strict_types=1);

fwrite(
    STDERR,
    "Deprecated: use bump-packages.php via release-finalize.sh. Release tags are now derived only from git tags, not composer.json versions.\n"
);
exit(1);
