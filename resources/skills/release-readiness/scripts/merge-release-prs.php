#!/usr/bin/env php
<?php

declare(strict_types=1);

fwrite(
    STDERR,
    "Deprecated: release PR merge automation was removed. Semitexa releases are now tag-driven from master after a green preflight.\n"
);
exit(1);
