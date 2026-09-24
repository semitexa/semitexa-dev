<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Quality\QualityScope;
use Semitexa\Dev\Application\Service\Quality\Verdict;

/**
 * Agent A edits semitexa-orm while agent B has an uncommitted new skip in
 * semitexa-webhooks. A's verification must not fail for B's work; B's must.
 */
final class QualityScopeTest extends TestCase
{
    #[Test]
    public function a_rise_in_another_repo_is_not_this_changes_to_answer_for(): void
    {
        $verdict = new Verdict('tests.skip-calls', Verdict::WORSE, 10, 12, [
            'semitexa-webhooks' => ['from' => 5, 'to' => 6],
            'modules/Playground' => ['from' => 0, 'to' => 1],
            'semitexa-orm' => ['from' => 4, 'to' => 3],
        ]);

        self::assertSame([], (new QualityScope(['packages/semitexa-orm']))->rises($verdict), 'orm only improved');
        self::assertSame(['semitexa-webhooks'], array_keys((new QualityScope(['packages/semitexa-webhooks']))->rises($verdict)));
        self::assertSame(['modules/Playground'], array_keys((new QualityScope(['src/modules/Playground']))->rises($verdict)));
        self::assertSame(['semitexa-webhooks', 'modules/Playground'], array_keys((new QualityScope(null))->rises($verdict)), 'unscoped: every rise');
    }

    #[Test]
    public function a_route_key_belongs_to_no_narrowed_scope(): void
    {
        self::assertFalse((new QualityScope(['packages/semitexa-demo']))->owns('GET /demo'));
        self::assertTrue((new QualityScope(null))->owns('GET /demo'));
    }
}
