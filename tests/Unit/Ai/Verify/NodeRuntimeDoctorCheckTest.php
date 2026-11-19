<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\DoctorStatus;
use Semitexa\Dev\Application\Service\Ai\Verify\JavaScriptSyntaxCheck;
use Semitexa\Dev\Application\Service\Ai\Verify\NodeRuntimeDoctorCheck;

final class NodeRuntimeDoctorCheckTest extends TestCase
{
    public function test_a_runtime_without_node_is_warned_with_the_layer_to_add(): void
    {
        $result = (new NodeRuntimeDoctorCheck())->evaluate(JavaScriptSyntaxCheck::findNode(sys_get_temp_dir() . '/no-such-node'));

        self::assertSame(DoctorStatus::Warn, $result->status);
        self::assertStringContainsString('does not close', $result->message);
        self::assertSame(JavaScriptSyntaxCheck::NODE_FIX, $result->hint);
        self::assertStringContainsString('RUN apk add --no-cache nodejs', (string) $result->hint);
    }

    public function test_a_runtime_with_node_passes(): void
    {
        $result = (new NodeRuntimeDoctorCheck())->evaluate('/usr/bin/node');

        self::assertSame(DoctorStatus::Pass, $result->status);
    }
}
