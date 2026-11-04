<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

trait SampleHelperTrait
{
    /** Checks the value against a regex. */
    protected function checkPattern(array &$errors, string $pattern): void {}

    public function helpPublicly(): void {}

    private function hidden(): void {}
}
