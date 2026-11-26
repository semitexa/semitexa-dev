<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\DocsTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;

/**
 * Step 6 of one source for bin/semitexa: docs:reference reads the commands
 * bin/semitexa handles itself from their help lines, so a change to the
 * script, at its scaffold source or the root copy, rebuilds the reference to
 * diff it, as a changed .php does. Before, only a .php triggered it, and a
 * renamed wrapper command left the reference page wrong until the next PHP
 * change happened to rebuild it.
 */
final class DocsReferenceTriggerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function readByTheReference(): array
    {
        return [
            'a PHP file'                  => ['packages/semitexa-core/src/Console/Command/X.php'],
            'the scaffold bin/semitexa'   => ['packages/semitexa-installer/scaffold/bin/semitexa'],
            'the root bin/semitexa'       => ['bin/semitexa'],
        ];
    }

    #[Test]
    #[DataProvider('readByTheReference')]
    public function a_source_of_the_reference_schedules_its_check(string $path): void
    {
        $target = self::referenceTarget($path);

        self::assertNotNull($target, "{$path} must schedule docs:reference");
        self::assertSame([$path], $target->triggeredBy);
        self::assertSame(['--check' => true], $target->commandInput);
    }

    /** @return array<string, array{string}> */
    public static function notReadByIt(): array
    {
        return [
            'another shell script'     => ['packages/semitexa-ultimate/tools/stack-smoke.sh'],
            'a file named like it'     => ['packages/semitexa-installer/scaffold/bin/semitexa.bak'],
            'ultimate\'s old copy path' => ['packages/semitexa-ultimate/bin/semitexa'],
        ];
    }

    #[Test]
    #[DataProvider('notReadByIt')]
    public function another_file_does_not(string $path): void
    {
        self::assertNull(self::referenceTarget($path), "{$path} is not read by docs:reference");
    }

    private static function referenceTarget(string $path): ?VerificationTarget
    {
        $targets = DocsTargets::of([new ChangedFile($path, ChangedFile::KIND_NON_PHP, ChangedFile::STATUS_MODIFIED)], sys_get_temp_dir());
        foreach ($targets as $target) {
            if ($target->id === 'docs:reference') {
                return $target;
            }
        }

        return null;
    }
}
