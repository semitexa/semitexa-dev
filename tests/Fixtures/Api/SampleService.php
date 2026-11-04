<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

final class SampleService extends SampleBase
{
    public const string LIMIT = 'limit';

    public string $pattern = '';

    public private(set) string $owner = '';

    public function perform(SampleExplosive $with = new SampleExplosive(), int ...$rest): void {}

    public static function make(): self
    {
        return new self();
    }

    public function &byReference(): array
    {
        static $held = [];

        return $held;
    }

    /** @deprecated Use make() */
    public function old(): void {}

    public function __invoke(): void {}

    protected function mustBeWritten(): void {}

    protected function notForCallers(): void {}
}
