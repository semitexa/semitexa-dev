<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Work\WorkId;

class WorkIdTest extends TestCase
{
    public function test_accepts_reasonable_slugs(): void
    {
        WorkId::assertValid('ep-fix-auth');
        WorkId::assertValid('tk_01');
        WorkId::assertValid('a');
        WorkId::assertValid('0');
        WorkId::assertValid(str_repeat('a', 64));

        $this->addToAssertionCount(1); // only cares that nothing threw
    }

    public function test_rejects_path_traversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkId::assertValid('../etc/passwd');
    }

    public function test_rejects_uppercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkId::assertValid('EP-Fix');
    }

    public function test_rejects_spaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkId::assertValid('ep fix');
    }

    public function test_rejects_overlong_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkId::assertValid(str_repeat('a', 65));
    }

    public function test_rejects_leading_hyphen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkId::assertValid('-ep');
    }
}
