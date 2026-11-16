<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * idea-skills-install-for-any-agent: resources/skills-install.sh unpacks the
 * skills where any agent reads them, in a project or globally, and nothing it
 * unpacks reaches a commit. Run against a fixture skill tree inside a throwaway
 * git checkout, laid out as a project (vendor/semitexa/dev) or as the framework
 * workspace (packages/semitexa-dev), with a throwaway HOME for --global.
 */
final class SkillsInstallScriptTest extends TestCase
{
    /**
     * A test run inside a git hook inherits these, and they would point the
     * fixture commits and the script at the caller's checkout. Cleared first;
     * a test that passes one on purpose sets it after.
     */
    private const NO_INHERITED_GIT = 'unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_PREFIX; ';

    private string $tmp = '';
    private string $home = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/skills-install-' . bin2hex(random_bytes(6));
        $this->home = $this->tmp . '/home';
        mkdir($this->home, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    #[Test]
    public function a_project_installs_its_skills_where_the_agent_reads_them_and_excludes_each_one(): void
    {
        $project = $this->project();

        [$exit, $out] = $this->runScript($project, '--agent=claude');

        self::assertSame(0, $exit, $out);
        self::assertFileExists($project . '/.claude/skills/demo/SKILL.md');
        self::assertFileExists($project . '/.claude/skills/demo/scripts/run.sh', 'the whole skill directory, scripts included');
        self::assertFileExists($project . '/.claude/skills/shared/SKILL.md');
        self::assertStringContainsString('the project edition', (string) file_get_contents($project . '/.claude/skills/shared/SKILL.md'));
        self::assertDirectoryDoesNotExist($project . '/.claude/skills/ws-only', 'a workspace skill never reaches a project');
        self::assertSame(['/.claude/skills/demo/', '/.claude/skills/shared/'], $this->excludeLines($project));
        self::assertSame('', $this->git($project, 'status --porcelain --untracked-files=all -- .claude'), 'nothing installed shows up for a commit');
    }

    #[Test]
    public function a_project_lists_only_what_it_can_install(): void
    {
        [$exit, $out] = $this->runScript($this->project());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('demo (project)', $out);
        self::assertStringContainsString('shared (project)', $out);
        self::assertStringNotContainsString('ws-only', $out);
    }

    #[Test]
    public function a_workspace_skill_is_refused_in_a_project_by_name(): void
    {
        [$exit, $out] = $this->runScript($this->project(), '--agent=claude', '--skill=ws-only');

        self::assertSame(1, $exit);
        self::assertStringContainsString("'ws-only' belongs to the framework workspace", $out);
    }

    #[Test]
    public function a_global_install_goes_to_the_agents_home_and_touches_no_repository(): void
    {
        $project = $this->project();

        [$exit, $out] = $this->runScript($project, '--agent=codex', '--global');

        self::assertSame(0, $exit, $out);
        self::assertFileExists($this->home . '/.codex/skills/demo/SKILL.md');
        self::assertSame([], $this->excludeLines($project));
    }

    #[Test]
    public function an_edited_copy_is_never_replaced_silently(): void
    {
        $project = $this->project();
        $this->runScript($project, '--agent=claude', '--skill=demo');
        file_put_contents($project . '/.claude/skills/demo/SKILL.md', "my own notes\n", FILE_APPEND);

        [$again, $againOut] = $this->runScript($project, '--agent=claude', '--skill=demo');
        [$check, $checkOut] = $this->runScript($project, '--check', '--agent=claude');

        self::assertSame(1, $again);
        self::assertStringContainsString('was edited after it was installed: move your edit out, delete it, and install again', $againOut);
        self::assertStringContainsString('my own notes', (string) file_get_contents($project . '/.claude/skills/demo/SKILL.md'));
        self::assertSame(1, $check);
        self::assertStringContainsString('edited here: ' . $project . '/.claude/skills/demo', $checkOut);
    }

    #[Test]
    public function check_says_when_a_newer_version_is_available(): void
    {
        $project = $this->project();
        $this->runScript($project, '--agent=claude', '--skill=demo');
        [$clean, $cleanOut] = $this->runScript($project, '--check', '--agent=claude');
        file_put_contents($this->resources($project) . '/skills-project/demo/SKILL.md', "a newer step\n", FILE_APPEND);

        [$stale, $staleOut] = $this->runScript($project, '--check', '--agent=claude');

        self::assertSame(0, $clean, $cleanOut);
        self::assertSame(1, $stale);
        self::assertStringContainsString('newer version available: demo', $staleOut);
    }

    #[Test]
    public function a_path_git_tracks_is_refused(): void
    {
        $project = $this->project();
        mkdir($project . '/.agents/skills/demo', 0777, true);
        file_put_contents($project . '/.agents/skills/demo/SKILL.md', "committed by the project\n");
        $this->git($project, 'add -f .agents/skills/demo/SKILL.md');
        $this->git($project, 'commit -q -m tracked');

        [$exit, $out] = $this->runScript($project, '--agent=agents', '--skill=demo');

        self::assertSame(1, $exit);
        self::assertStringContainsString('is tracked by git', $out);
        self::assertSame("committed by the project\n", file_get_contents($project . '/.agents/skills/demo/SKILL.md'));
    }

    #[Test]
    public function a_directory_it_did_not_install_is_left_alone(): void
    {
        $project = $this->project();
        mkdir($project . '/.claude/skills/demo', 0777, true);
        file_put_contents($project . '/.claude/skills/demo/SKILL.md', "someone else's\n");

        [$exit, $out] = $this->runScript($project, '--agent=claude', '--skill=demo');

        self::assertSame(1, $exit);
        self::assertStringContainsString('exists and was not installed by this script: move it away to install here', $out);
        self::assertSame("someone else's\n", file_get_contents($project . '/.claude/skills/demo/SKILL.md'));
    }

    #[Test]
    public function a_relative_directory_is_excluded_as_git_names_it(): void
    {
        $project = $this->project();

        [$exit, $out] = $this->runScript($project, '--into=./.agents/skills', '--skill=demo');

        self::assertSame(0, $exit, $out);
        self::assertSame(['/.agents/skills/demo/'], $this->excludeLines($project), 'no ./ segment: git would not match it');
        self::assertSame('', $this->git($project, 'status --porcelain --untracked-files=all -- .agents'));
    }

    #[Test]
    public function a_project_inside_a_larger_repository_is_excluded_by_its_path_there(): void
    {
        $outer = $this->checkout('outer');
        $project = $outer . '/app';
        $this->tree($this->resources($project));

        [$exit, $out] = $this->runScript($project, '--agent=claude', '--skill=demo');

        self::assertSame(0, $exit, $out);
        self::assertSame(['/app/.claude/skills/demo/'], $this->excludeLines($outer));
        self::assertSame('', $this->git($outer, 'status --porcelain --untracked-files=all -- app/.claude'));
    }

    #[Test]
    public function a_directory_inside_another_checkout_is_excluded_in_that_checkout(): void
    {
        $project = $this->project();
        $other = $this->checkout('other');

        [$exit, $out] = $this->runScript($project, '--into=' . $other . '/agent/skills', '--skill=demo');

        self::assertSame(0, $exit, $out);
        self::assertFileExists($other . '/agent/skills/demo/SKILL.md');
        self::assertSame(['/agent/skills/demo/'], $this->excludeLines($other));
        self::assertSame('', $this->git($other, 'status --porcelain --untracked-files=all'), 'nothing to commit in the other checkout either');
        self::assertSame([], $this->excludeLines($project));
    }

    #[Test]
    public function a_path_another_checkout_tracks_is_refused(): void
    {
        $project = $this->project();
        $other = $this->checkout('other');
        mkdir($other . '/agent/skills/demo', 0777, true);
        file_put_contents($other . '/agent/skills/demo/SKILL.md', "committed over there\n");
        $this->git($other, 'add agent/skills/demo/SKILL.md');
        $this->git($other, 'commit -q -m tracked');

        [$exit, $out] = $this->runScript($project, '--into=' . $other . '/agent/skills', '--skill=demo');

        self::assertSame(1, $exit);
        self::assertStringContainsString($other . '/agent/skills/demo is tracked by git', $out);
        self::assertSame("committed over there\n", file_get_contents($other . '/agent/skills/demo/SKILL.md'));
    }

    #[Test]
    public function remove_takes_the_copies_and_their_exclude_lines_away(): void
    {
        $project = $this->project();
        [$installed, $installedOut] = $this->runScript($project, '--agent=claude');
        self::assertSame(0, $installed, $installedOut);
        self::assertFileExists($project . '/.claude/skills/demo/SKILL.md');
        self::assertFileExists($project . '/.claude/skills/shared/SKILL.md');
        self::assertSame(['/.claude/skills/demo/', '/.claude/skills/shared/'], $this->excludeLines($project));

        [$exit, $out] = $this->runScript($project, '--remove', '--agent=claude');

        self::assertSame(0, $exit, $out);
        self::assertDirectoryDoesNotExist($project . '/.claude/skills/demo');
        self::assertDirectoryDoesNotExist($project . '/.claude/skills/shared');
        self::assertSame([], $this->excludeLines($project));
    }

    #[Test]
    public function remove_keeps_a_copy_someone_committed(): void
    {
        $project = $this->project();
        [$installed, $installedOut] = $this->runScript($project, '--agent=claude', '--skill=demo');
        self::assertSame(0, $installed, $installedOut);
        $this->git($project, 'add -f .claude/skills/demo');
        $this->git($project, 'commit -q -m committed-anyway');

        [$exit, $out] = $this->runScript($project, '--remove', '--agent=claude');

        self::assertSame(1, $exit);
        self::assertStringContainsString('kept ' . $project . '/.claude/skills/demo: git tracks it', $out);
        self::assertFileExists($project . '/.claude/skills/demo/SKILL.md');
        self::assertSame('', $this->git($project, 'status --porcelain -- .claude'), 'no tracked file deleted from the working tree');
    }

    #[Test]
    public function the_workspace_takes_its_own_edition_and_never_installs_one_globally(): void
    {
        $workspace = $this->tmp . '/workspace';
        $this->tree($workspace . '/packages/semitexa-dev/resources');
        $this->git($workspace, 'init -q');

        [$local, $localOut] = $this->runScript($workspace, '--agent=claude', '--skill=shared', '--skill=ws-only');
        [$global, $globalOut] = $this->runScript($workspace, '--agent=claude', '--global', '--skill=shared');
        [$refused, $refusedOut] = $this->runScript($workspace, '--agent=claude', '--global', '--skill=ws-only');

        self::assertSame(0, $local, $localOut);
        self::assertStringContainsString('the workspace edition', (string) file_get_contents($workspace . '/.claude/skills/shared/SKILL.md'));
        self::assertFileExists($workspace . '/.claude/skills/ws-only/SKILL.md');
        self::assertSame(0, $global, $globalOut);
        self::assertStringContainsString('the project edition', (string) file_get_contents($this->home . '/.claude/skills/shared/SKILL.md'), 'globally, the edition that fits every project');
        self::assertSame(1, $refused);
        self::assertStringContainsString("'ws-only' is a workspace skill: installed in the workspace only, never globally", $refusedOut);
    }

    #[Test]
    public function git_is_asked_about_the_skills_directory_not_an_inherited_repository(): void
    {
        $project = $this->project();
        $other = $this->checkout('other');

        [$exit, $out] = $this->runScriptWith(
            ['GIT_DIR' => $project . '/.git', 'GIT_WORK_TREE' => $project],
            $project,
            '--into=' . $other . '/agent/skills',
            '--skill=demo',
        );

        self::assertSame(0, $exit, $out);
        self::assertSame(['/agent/skills/demo/'], $this->excludeLines($other), 'the checkout that holds the copy excludes it');
        self::assertSame([], $this->excludeLines($project), 'not the one the environment pointed at');
    }

    #[Test]
    public function a_checkout_git_cannot_answer_for_stops_the_run(): void
    {
        $project = $this->project();
        $other = $this->checkout('other');
        file_put_contents($other . '/.git/HEAD', "not a ref\n");

        [$exit, $out] = $this->runScript($project, '--into=' . $other . '/agent/skills', '--skill=demo');

        self::assertSame(1, $exit);
        self::assertStringContainsString('git cannot answer for the checkout at ' . $other, $out);
        self::assertDirectoryDoesNotExist($other . '/agent/skills/demo', 'not installed unprotected');
    }

    #[Test]
    public function a_tracked_listing_git_cannot_give_stops_the_run(): void
    {
        $project = $this->project();
        file_put_contents($project . '/.git/index', 'not an index');

        [$exit, $out] = $this->runScript($project, '--agent=claude', '--skill=demo');

        self::assertSame(1, $exit);
        self::assertStringContainsString('git cannot list what ' . $project . ' tracks', $out);
        self::assertDirectoryDoesNotExist($project . '/.claude/skills/demo');
    }

    #[Test]
    public function remove_keeps_a_copy_edited_after_it_was_installed(): void
    {
        $project = $this->project();
        [$installed, $installedOut] = $this->runScript($project, '--agent=claude', '--skill=demo');
        self::assertSame(0, $installed, $installedOut);
        file_put_contents($project . '/.claude/skills/demo/SKILL.md', "my own notes\n", FILE_APPEND);

        [$exit, $out] = $this->runScript($project, '--remove', '--agent=claude');

        self::assertSame(1, $exit);
        self::assertStringContainsString('kept ' . $project . '/.claude/skills/demo: it changed after it was installed', $out);
        self::assertStringContainsString('my own notes', (string) file_get_contents($project . '/.claude/skills/demo/SKILL.md'));
    }

    #[Test]
    public function a_skill_directory_that_became_a_checkout_of_its_own_is_left_alone(): void
    {
        $project = $this->project();
        [$installed, $installedOut] = $this->runScript($project, '--agent=claude', '--skill=demo');
        self::assertSame(0, $installed, $installedOut);
        $this->git($project . '/.claude/skills/demo', 'init -q');

        [$again, $againOut] = $this->runScript($project, '--agent=claude', '--skill=demo');
        [$remove, $removeOut] = $this->runScript($project, '--remove', '--agent=claude');

        self::assertSame(1, $again);
        self::assertStringContainsString('was edited after it was installed', $againOut);
        self::assertSame(1, $remove);
        self::assertStringContainsString('kept ' . $project . '/.claude/skills/demo', $removeOut);
        self::assertDirectoryExists($project . '/.claude/skills/demo/.git', 'its history is still there');
    }

    #[Test]
    public function an_exclude_file_that_cannot_be_read_is_left_as_it_was(): void
    {
        $project = $this->project();
        [$installed, $installedOut] = $this->runScript($project, '--agent=claude', '--skill=demo');
        self::assertSame(0, $installed, $installedOut);
        self::assertSame(['/.claude/skills/demo/'], $this->excludeLines($project));

        // A grep that cannot read the file it filters, whoever runs the test.
        $bin = $this->tmp . '/bin';
        mkdir($bin);
        exec('command -v grep', $found);
        file_put_contents($bin . '/grep', "#!/bin/sh\ncase \"\$1\" in -vxF) exit 2 ;; esac\nexec " . escapeshellarg($found[0] ?? '/bin/grep') . " \"\$@\"\n");
        chmod($bin . '/grep', 0755);
        [$exit, $out] = $this->runScriptWith(['PATH' => $bin . ':' . getenv('PATH')], $project, '--remove', '--agent=claude');

        self::assertSame(1, $exit);
        self::assertStringContainsString('cannot read ' . $project . '/.git/info/exclude, so it is left as it was', $out);
        self::assertSame(['/.claude/skills/demo/'], $this->excludeLines($project), 'not replaced by what was not read');
    }

    private function project(): string
    {
        $project = $this->tmp . '/project';
        $this->tree($this->resources($project));
        $this->git($project, 'init -q');
        file_put_contents($project . '/README.md', "a project\n");
        file_put_contents($project . '/.gitignore', "/vendor/\n"); // as a project's own does
        $this->git($project, 'add README.md .gitignore');
        $this->git($project, 'commit -q -m start');

        return $project;
    }

    private function checkout(string $name): string
    {
        $dir = $this->tmp . '/' . $name;
        mkdir($dir, 0777, true);
        $this->git($dir, 'init -q');
        file_put_contents($dir . '/README.md', "{$name}\n");
        $this->git($dir, 'add README.md');
        $this->git($dir, 'commit -q -m start');

        return $dir;
    }

    private function resources(string $project): string
    {
        return $project . '/vendor/semitexa/dev/resources';
    }

    private function tree(string $resources): void
    {
        $skills = [
            'skills-project/demo' => 'demo, the project edition',
            'skills-project/shared' => 'shared, the project edition',
            'skills/shared' => 'shared, the workspace edition',
            'skills/ws-only' => 'ws-only, the workspace edition',
        ];
        foreach ($skills as $dir => $text) {
            mkdir($resources . '/' . $dir . '/scripts', 0777, true);
            file_put_contents($resources . '/' . $dir . '/SKILL.md', "---\nname: x\ndescription: {$text}\n---\n{$text}\n");
            file_put_contents($resources . '/' . $dir . '/scripts/run.sh', "#!/bin/sh\necho run\n");
        }
        file_put_contents($resources . '/skills.manifest', implode("\n", [
            '# fixture',
            'demo      project    skills-project/demo',
            'shared    workspace  skills/shared',
            'shared    project    skills-project/shared',
            'ws-only   workspace  skills/ws-only',
        ]) . "\n");
        copy(dirname(__DIR__, 3) . '/resources/skills-install.sh', $resources . '/skills-install.sh');
    }

    /** @return array{int, string} */
    private function runScript(string $root, string ...$args): array
    {
        return $this->runScriptWith([], $root, ...$args);
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function runScriptWith(array $env, string $root, string ...$args): array
    {
        $script = is_dir($root . '/packages')
            ? $root . '/packages/semitexa-dev/resources/skills-install.sh'
            : $this->resources($root) . '/skills-install.sh';
        $env['HOME'] = $this->home;
        $assignments = '';
        foreach ($env as $name => $value) {
            $assignments .= $name . '=' . escapeshellarg($value) . ' ';
        }
        $command = self::NO_INHERITED_GIT . 'cd ' . escapeshellarg($root) . ' && ' . $assignments
            . 'sh ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($command, $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }

    private function git(string $root, string $args): string
    {
        exec(self::NO_INHERITED_GIT . 'git -C ' . escapeshellarg($root) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, 'git ' . $args . ': ' . implode("\n", $lines));

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function excludeLines(string $checkout): array
    {
        $file = $checkout . '/.git/info/exclude';
        if (!is_file($file)) {
            return [];
        }
        $lines = file($file);
        if ($lines === false) {
            self::fail('cannot read ' . $file);
        }

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }
}
