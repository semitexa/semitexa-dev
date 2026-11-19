<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\RatchetRecordTargets;

/**
 * The map of ratchet records to their readers, held against the package.
 *
 * The planner trusts the map: a record renamed, or a test moved, and the map
 * names a path that is not there, the record is planned no test and verifies
 * `incomplete` again with nothing saying which line went stale.
 */
final class RatchetRecordTargetsTest extends TestCase
{
    private const PACKAGE = __DIR__ . '/../../../..';

    #[Test]
    public function every_record_and_every_reader_named_is_in_the_package(): void
    {
        self::assertSame(
            ['resources/phpstan/phpstan-ceiling.json', 'resources/phpstan/semantic-rule-counts.json'],
            array_keys(RatchetRecordTargets::READERS),
        );
        foreach (RatchetRecordTargets::READERS as $record => $reader) {
            self::assertFileExists(self::PACKAGE . '/' . $record);
            self::assertFileExists(self::PACKAGE . '/' . $reader);
            // The reader reads THAT record: a test naming another file would count this one as read.
            self::assertStringContainsString("/../../../{$record}'", (string) file_get_contents(self::PACKAGE . '/' . $reader));
        }
    }

    #[Test]
    public function every_json_record_of_the_directory_has_a_reader(): void
    {
        $records = array_map(
            static fn (string $path): string => 'resources/phpstan/' . basename($path),
            glob(self::PACKAGE . '/resources/phpstan/*.json') ?: [],
        );
        sort($records);

        self::assertSame(array_keys(RatchetRecordTargets::READERS), $records, 'a new record needs a test that reads it, named in RatchetRecordTargets::READERS, or a commit changing only it cannot be pushed');
    }
}
