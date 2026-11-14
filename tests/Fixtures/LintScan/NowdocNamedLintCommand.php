<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\LintScan;

use Semitexa\Core\Attribute\AsCommand;

/** A lint command named by a nowdoc (review, dev#254). */
#[AsCommand(description: ")]", name: <<<'NAME'
    lint:fixture-nowdoc
    NAME)]
final class NowdocNamedLintCommand
{
}
