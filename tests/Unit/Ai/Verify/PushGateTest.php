<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\PushHook;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\ReceiptForCommit;

/**
 * A push asks whether a passing ai:verify run vouches for each commit it sends
 * (tk-prepush-refuses-red-verify). On 2026-11-12 a red run was pushed for the
 * third time: a command chain printed the verdict and committed anyway.
 */
final class PushGateTest extends TestCase
{
    private string $root;
    private string $repo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-push-gate-' . bin2hex(random_bytes(4));
        $this->repo = $this->root . '/packages/semitexa-x';
        mkdir($this->repo . '/src', 0o755, true);
        mkdir($this->root . '/var/run/verify-receipts', 0o755, true);
        file_put_contents($this->repo . '/composer.json', '{"name": "semitexa/x"}');
        file_put_contents($this->repo . '/src/A.php', "<?php // a\n");
        $this->git('init -q -b develop && %s add -A && %s commit -q -m init');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_commit_whose_bytes_a_passing_run_checked_is_covered(): void
    {
        $sha = $this->commit('src/B.php', "<?php // b\n");
        $this->receipt('rcpt-1', 'pass', ['packages/semitexa-x/src/B.php' => hash('sha256', "<?php // b\n")]);

        self::assertSame('rcpt-1', (new ReceiptForCommit($this->root))->find('packages/semitexa-x', $sha));
    }

    #[Test]
    public function a_red_run_other_bytes_or_an_unchecked_file_cover_nothing(): void
    {
        $sha = $this->commit('src/B.php', "<?php // b\n");
        $this->receipt('rcpt-1', 'fail', ['packages/semitexa-x/src/B.php' => hash('sha256', "<?php // b\n")]);
        $this->receipt('rcpt-2', 'pass', ['packages/semitexa-x/src/B.php' => hash('sha256', "<?php // an earlier b\n")]);
        $this->receipt('rcpt-3', 'pass', ['packages/semitexa-x/src/Other.php' => hash('sha256', 'x')]);
        $this->receipt('rcpt-4', 'pass', ['packages/semitexa-y/src/B.php' => hash('sha256', "<?php // b\n")]);

        self::assertNull((new ReceiptForCommit($this->root))->find('packages/semitexa-x', $sha));
    }

    #[Test]
    public function the_hook_lets_a_push_of_nothing_new_through_and_asks_about_a_new_commit(): void
    {
        self::assertSame(['packages/semitexa-x' => PushHook::WRITTEN], (new PushHook())->install(['packages/semitexa-x' => $this->repo]));
        mkdir($this->root . '/bin');
        file_put_contents($this->root . '/bin/semitexa', "#!/bin/sh\necho \"asked: \$*\"\nexit 1\n");
        chmod($this->root . '/bin/semitexa', 0o755);

        $old = trim((string) shell_exec('git -C ' . escapeshellarg($this->repo) . ' rev-parse HEAD'));
        $this->git('update-ref refs/remotes/origin/develop HEAD');
        [$status] = $this->hook("refs/heads/develop {$old} refs/heads/develop 0000000000000000000000000000000000000000\n");
        self::assertSame(0, $status, 'a fast-forward to what a remote already has sends nothing to ask about');

        $new = $this->commit('src/C.php', "<?php // c\n");
        [$status, $out] = $this->hook("refs/heads/develop {$new} refs/heads/develop {$old}\n");
        self::assertSame(1, $status, 'the gate refuses when ai:verify:covers does');
        self::assertSame(["asked: ai:verify:covers --repo=packages/semitexa-x {$new}"], $out);
    }

    #[Test]
    public function a_hook_someone_else_wrote_is_left_alone(): void
    {
        $hooks = $this->repo . '/.git/hooks';
        file_put_contents($hooks . '/pre-push', "#!/bin/sh\necho mine\n");

        self::assertSame(['packages/semitexa-x' => PushHook::FOREIGN], (new PushHook())->install(['packages/semitexa-x' => $this->repo]));
        self::assertSame("#!/bin/sh\necho mine\n", file_get_contents($hooks . '/pre-push'));
    }

    /** @return array{int, list<string>} */
    private function hook(string $stdin): array
    {
        $proc = proc_open(['sh', $this->repo . '/.git/hooks/pre-push', 'origin', 'git@example.invalid:x.git'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repo);
        self::assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out === '' ? [] : explode("\n", $out)];
    }

    private function commit(string $file, string $contents): string
    {
        file_put_contents($this->repo . '/' . $file, $contents);
        $this->git('add -A && %s commit -q -m change');

        return trim((string) shell_exec('git -C ' . escapeshellarg($this->repo) . ' rev-parse HEAD'));
    }

    /** @param array<string, string> $tree */
    private function receipt(string $id, string $verdict, array $tree): void
    {
        file_put_contents($this->root . '/var/run/verify-receipts/' . $id . '.json', (string) json_encode(['id' => $id, 'verdict' => $verdict, 'tree' => $tree]));
    }

    private function git(string $commands): void
    {
        $git = 'git -C ' . escapeshellarg($this->repo) . ' -c user.email=t@example.invalid -c user.name=t';
        exec($git . ' ' . str_replace('%s', $git, $commands), $out, $code);
        self::assertSame(0, $code, $commands);
    }
}
