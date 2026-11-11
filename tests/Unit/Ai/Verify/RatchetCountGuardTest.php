<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ProjectGuardTargets;

/**
 * Adding or removing a ratchet runs the test that counts them
 * (tk-verify-misses-rule-catalog-count). dev#233 added the seventh ratchet;
 * ai:verify passed, and only the release suite saw RuleCatalogTest still
 * expecting six.
 */
final class RatchetCountGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-ratchet-count-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/' . ProjectGuardTargets::RATCHET_SUITE, 0o755, true);
        mkdir(dirname($this->root . '/' . ProjectGuardTargets::RULE_CATALOG_TEST), 0o755, true);
        touch($this->root . '/' . ProjectGuardTargets::RULE_CATALOG_TEST);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function an_added_or_removed_ratchet_runs_the_rule_catalog_test(): void
    {
        $id = 'phpunit:' . ProjectGuardTargets::RULE_CATALOG_TEST;
        $ids = fn (ChangedFile $f): array => array_map(
            static fn ($t): string => $t->id,
            (new ProjectGuardTargets($this->root))->targets([$f], 'standard'),
        );
        $suite = ProjectGuardTargets::RATCHET_SUITE;

        self::assertContains($id, $ids(new ChangedFile("{$suite}/NewRatchetTest.php", ChangedFile::KIND_PHP_OTHER, ChangedFile::STATUS_ADDED)));
        self::assertContains($id, $ids(new ChangedFile("{$suite}/OldRatchetTest.php", ChangedFile::KIND_PHP_OTHER, ChangedFile::STATUS_DELETED)));
        self::assertNotContains($id, $ids(new ChangedFile('packages/semitexa-dev/src/Foo.php', ChangedFile::KIND_PHP_OTHER)), 'nothing else pays for it');
    }
}
