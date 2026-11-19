<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\JavaScriptSyntaxCheck;
use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/** The check against node itself is in {@see VerificationExecutorTest}; this is the runtime that has none. */
final class JavaScriptSyntaxCheckTest extends TestCase
{
    /**
     * Apartspace, 19.11: their own Dockerfile has no node, the run stayed
     * incomplete and the task could not close, with nothing saying why the
     * image lacked it. The gate stays shut, so the line has to name the way out.
     */
    public function test_the_line_of_a_runtime_without_node_names_the_layer_to_add_and_the_rebuild(): void
    {
        $runner = new class implements ProcessRunner {
            public int $calls = 0;

            public function run(array $command, string $cwd): array
            {
                ++$this->calls;

                return ['exit' => 0, 'output' => ''];
            }
        };
        $rel = 'src/collab-form.js';
        $target = new VerificationTarget(VerificationTarget::TYPE_SYNTAX, "syntax:{$rel}", 'r', [$rel], filePath: $rel);

        $result = (new JavaScriptSyntaxCheck($runner, sys_get_temp_dir(), sys_get_temp_dir() . '/no-such-node'))
            ->check($target, $rel, sys_get_temp_dir() . '/' . $rel);

        self::assertSame(VerificationResult::STATUS_INCOMPLETE, $result->status, 'a file nobody read is never a pass');
        self::assertTrue($result->required);
        self::assertStringContainsString("{$rel} was NOT checked", $result->signal);
        self::assertStringContainsString('RUN apk add --no-cache nodejs', $result->signal);
        self::assertStringContainsString('bin/semitexa server:restart', $result->signal);
        self::assertSame(0, $runner->calls);
    }
}
