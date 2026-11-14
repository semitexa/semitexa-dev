<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\LintScan;

use Semitexa\Core\Attribute\AsCommand as Cmd;

/** A lint command named by a class constant, imported under an alias (review, dev#254). */
#[Cmd(name: self::NAME . '-check', aliases: ['lint:fixture-alias'])]
final class ConstantNamedLintCommand
{
    public const NAME = 'lint:fixture';
}
