<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A release run stops the workspace's semitexadev-* stack (the clone shares its
 * host ports) and, seen twice on 2026-11-12, never started it again. The stop
 * script now remembers that dev was up; release-restore-dev.sh starts it again.
 *
 * The real scripts run here, with docker and bin/semitexa stubbed.
 */
final class ReleaseRestoresDevStackTest extends TestCase
{
    private const SCRIPTS = __DIR__ . '/../../../resources/skills/release-readiness/scripts';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/release-dev-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/scripts', 0777, true);
        mkdir($this->dir . '/state');
        foreach (['release-stop-semitexa.sh', 'release-restore-dev.sh'] as $script) {
            copy(self::SCRIPTS . '/' . $script, $this->dir . '/scripts/' . $script);
            chmod($this->dir . '/scripts/' . $script, 0755);
        }
        file_put_contents($this->dir . '/scripts/common.sh', <<<SH
            RELEASE_ROOT={$this->dir}/rls
            DEV_ROOT={$this->dir}/dev
            RELEASE_STATE_DIR={$this->dir}/state
            require_cmd() { :; }
            info() { :; }
            ok() { :; }
            SH);
        foreach (['rls', 'dev'] as $root) {
            mkdir($this->dir . '/' . $root . '/bin', 0777, true);
            file_put_contents($this->dir . "/$root/docker-compose.yml", '');
            file_put_contents(
                $this->dir . "/$root/bin/semitexa",
                "#!/usr/bin/env bash\necho \"$root \$1\" >>" . $this->dir . "/calls\n",
            );
            chmod($this->dir . "/$root/bin/semitexa", 0755);
        }
        mkdir($this->dir . '/path');
        file_put_contents(
            $this->dir . '/path/docker',
            "#!/usr/bin/env bash\n[ \"\$1\" = ps ] && cat " . $this->dir . "/running\n[ \"\$1\" = stop ] && echo \"stop \$*\" >>" . $this->dir . "/calls\nexit 0\n",
        );
        chmod($this->dir . '/path/docker', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function a_dev_stack_that_was_up_is_started_again_after_the_clone_goes_down(): void
    {
        file_put_contents($this->dir . '/running', "semitexadev-app-1\n");

        $this->runScript('release-stop-semitexa.sh');
        $this->runScript('release-restore-dev.sh');

        $calls = $this->calls();
        $starts = array_values(array_filter(
            $calls,
            static fn (string $c): bool => str_contains($c, 'server:'),
        ));
        self::assertSame('dev server:start', end($starts), 'dev starts last, after the clone is down');
        self::assertSame(1, count(array_keys($starts, 'dev server:start', true)));
        self::assertFileDoesNotExist($this->dir . '/state/dev-stack-was-running');
    }

    #[Test]
    public function a_machine_with_no_dev_stack_is_left_alone(): void
    {
        file_put_contents($this->dir . '/running', "semitexarls-app-1\n");

        $this->runScript('release-stop-semitexa.sh');
        $this->runScript('release-restore-dev.sh');

        $calls = $this->calls();
        self::assertContains('rls server:stop', $calls, 'the stop script ran, so an empty list is not what passes below');
        self::assertNotContains('dev server:start', $calls);
    }

    #[Test]
    public function a_second_stop_keeps_the_fact_that_dev_was_up(): void
    {
        file_put_contents($this->dir . '/running', "semitexadev-app-1\n");
        $this->runScript('release-stop-semitexa.sh');

        file_put_contents($this->dir . '/running', "semitexarls-app-1\n");
        $this->runScript('release-stop-semitexa.sh');

        self::assertFileExists($this->dir . '/state/dev-stack-was-running');
    }

    private function runScript(string $script): void
    {
        exec(
            'PATH=' . escapeshellarg($this->dir . '/path') . ':$PATH bash ' . escapeshellarg($this->dir . '/scripts/' . $script) . ' 2>&1',
            $out,
            $code,
        );
        self::assertSame(0, $code, implode("\n", $out));
    }

    /** @return list<string> */
    private function calls(): array
    {
        $file = $this->dir . '/calls';

        return is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
    }
}
