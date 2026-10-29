<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * release-sync-masters.sh leaves out a package whose origin has no master
 * (semitexa-notifications, on develop only, stopped the cut of 2026-10-29).
 * Only that answer may leave one out: a query that failed read as "no master"
 * would drop a package that has one from the release.
 */
final class ReleaseRemoteHasMasterTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/release-master-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function a_remote_with_master_has_one(): void
    {
        self::assertSame([0, ['yes']], $this->hasMaster($this->remote(['master', 'develop'])));
    }

    #[Test]
    public function a_remote_on_develop_only_has_none(): void
    {
        self::assertSame([0, ['no']], $this->hasMaster($this->remote(['develop'])));
    }

    #[Test]
    public function a_remote_that_cannot_be_read_is_an_error_not_a_missing_master(): void
    {
        self::assertSame([1, []], $this->hasMaster($this->dir . '/nowhere.git'));
    }

    /** @param list<string> $branches */
    private function remote(array $branches): string
    {
        $work = $this->dir . '/work';
        $bare = $this->dir . '/origin.git';
        $git = static fn (string $args): string => (string) shell_exec('git ' . $args . ' 2>&1');
        $git('init -q -b ' . escapeshellarg($branches[0]) . ' ' . escapeshellarg($work));
        $git('-C ' . escapeshellarg($work) . ' -c user.name=t -c user.email=t@example.test commit -q --allow-empty -m first');
        foreach (array_slice($branches, 1) as $branch) {
            $git('-C ' . escapeshellarg($work) . ' branch ' . escapeshellarg($branch));
        }
        $git('clone -q --bare ' . escapeshellarg($work) . ' ' . escapeshellarg($bare));

        return $bare;
    }

    /** @return array{int, list<string>} */
    private function hasMaster(string $url): array
    {
        $common = dirname(__DIR__, 3) . '/resources/skills/release-readiness/scripts/common.sh';
        exec(sprintf(
            'env -i PATH=/usr/bin:/bin HOME=%s bash -c %s 2>&1',
            escapeshellarg($this->dir),
            escapeshellarg('. ' . escapeshellarg($common) . ' && remote_has_master ' . escapeshellarg($url)),
        ), $out, $status);

        return [$status, $out];
    }
}
