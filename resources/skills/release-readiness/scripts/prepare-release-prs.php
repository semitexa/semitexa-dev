#!/usr/bin/env php
<?php

declare(strict_types=1);

fwrite(
    STDERR,
    "Deprecated: release version-bump PRs were removed. Semitexa package releases are tag-driven from master and no longer edit composer.json version fields.\n"
);
exit(1);
