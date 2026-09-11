<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiVerifyCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

final class VerificationVerdictTest extends TestCase
{
    public function testRequiredGapsCannotBeHiddenBySuccessfulTargets(): void
    {
        $target = new VerificationTarget('syntax', 'syntax:a', 'test', []);
        $pass = new VerificationResult($target, 'pass', 0, 'clean');
        $fail = new VerificationResult($target, 'fail', 1, 'violation');
        $gap = new VerificationResult($target, 'incomplete', 1, 'could not run');
        $requiredSkip = new VerificationResult($target, 'skipped', 0, 'required check absent');
        $optionalSkip = new VerificationResult($target, 'skipped', 0, 'not applicable', required: false);
        $unknown = new VerificationResult($target, 'unrecognized', 0, 'not evidence');
        $verdict = new \ReflectionMethod(AiVerifyCommand::class, 'verdict');
        $completed = new \ReflectionMethod(AiVerifyCommand::class, 'completed');
        $command = new AiVerifyCommand();
        foreach ([
            [[], 'incomplete', false],
            [[$pass], 'pass', true],
            [[$pass, $gap], 'incomplete', false],
            [[$pass, $requiredSkip], 'incomplete', false],
            [[$pass, $optionalSkip], 'pass', true],
            [[$optionalSkip], 'skipped', true],
            [[$pass, $unknown], 'incomplete', false],
            [[$fail, $gap], 'fail', false],
            [[$fail], 'fail', true],
        ] as [$results, $expectedVerdict, $expectedCompleted]) {
            self::assertSame($expectedVerdict, $verdict->invoke($command, $results));
            self::assertSame($expectedCompleted, $completed->invoke($command, $results));
        }
    }

    public function testResultSerializationNamesOptionalityAndCompletion(): void
    {
        $target = new VerificationTarget('syntax', 'syntax:a', 'test', []);
        $method = new \ReflectionMethod(AiVerifyCommand::class, 'serializeResult');
        $row = $method->invoke(new AiVerifyCommand(), new VerificationResult($target, 'skipped', 0, 'not applicable', required: false));
        self::assertFalse($row['required']);
        self::assertFalse($row['completed']);
        self::assertSame('not applicable', $row['signal']);
    }
}
