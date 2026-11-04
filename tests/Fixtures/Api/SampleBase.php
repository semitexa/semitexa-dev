<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

use Symfony\Component\Console\Command\Command;

/** A parent from outside the framework must not leak its methods. */
abstract class SampleBase extends Command
{
    protected function extensionPoint(): void {}

    public function inheritedByAll(): void {}

    abstract protected function mustBeWritten(): void;

    private function hidden(): void {}
}
