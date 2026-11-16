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
        self::assertStringContainsString('was edited after it was installed; --force replaces the edit', $againOut);
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
        self::assertStringContainsString('exists and was not installed by this script; --force replaces it', $out);
        self::assertSame("someone else's\n", file_get_contents($project . '/.claude/skills/demo/SKILL.md'));
    }

    #[Test]
    public function remove_takes_the_copies_and_their_exclude_lines_away(): void
    {
        $project = $this->project();
        $this->runScript($project, '--agent=claude');

        [$exit, $out] = $this->runScript($project, '--remove', '--agent=claude');

        self::assertSame(0, $exit, $out);
        self::assertDirectoryDoesNotExist($project . '/.claude/skills/demo');
        self::assertDirectoryDoesNotExist($project . '/.claude/skills/shared');
        self::assertSame([], $this->excludeLines($project));
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
        $script = is_dir($root . '/packages')
            ? $root . '/packages/semitexa-dev/resources/skills-install.sh'
            : $this->resources($root) . '/skills-install.sh';
        $command = 'cd ' . escapeshellarg($root) . ' && HOME=' . escapeshellarg($this->home)
            . ' sh ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($command, $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }

    private function git(string $root, string $args): string
    {
        exec('git -C ' . escapeshellarg($root) . ' -c user.name=t -c user.email=t@t ' . $args . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, 'git ' . $args . ': ' . implode("\n", $lines));

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function excludeLines(string $project): array
    {
        $file = $project . '/.git/info/exclude';
        if (!is_file($file)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', (array) file($file)),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }
}
