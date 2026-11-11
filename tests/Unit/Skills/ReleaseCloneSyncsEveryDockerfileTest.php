<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The release clone gets every Dockerfile its compose files build
 * (tk-e2e-runner-image-tag-without-axe).
 *
 * release-sync-clone-infra.sh synced docker-compose.test.yml, which names the
 * runner image semitexa/e2e-runner:1.59.1-axe4.13.0, but not
 * docker/e2e-runner/Dockerfile. Its rebuild then built a runner WITHOUT axe
 * from the clone's 2026-09-08 Dockerfile and tagged it with the axe tag, and an
 * image tag is the machine's, not the project's: every release replaced every
 * project's runner with one whose page checks cannot load (05.11, 10.11).
 */
final class ReleaseCloneSyncsEveryDockerfileTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../resources/skills/release-readiness/scripts/release-sync-clone-infra.sh';

    private const SCAFFOLD = __DIR__ . '/../../../../semitexa-installer/scaffold';

    #[Test]
    public function every_dockerfile_the_scaffold_builds_is_synced_into_the_release_clone(): void
    {
        $synced = $this->syncedFiles();

        // The ones known to be built, held wherever the package is installed.
        self::assertContains('docker-compose.test.yml', $synced);
        self::assertContains('docker/e2e-runner/Dockerfile', $synced);
        self::assertContains('Dockerfile', $synced);
        // The CLI the release verifies with is the one it ships (tk-release-clone-runs-old-cli).
        self::assertContains('bin/semitexa', $synced);

        // In the workspace the installer sits beside this package, and the rule
        // is read from its scaffold too, so a Dockerfile added there later is held
        // the day it is added. Installed under vendor/ (the stack smoke) there is
        // no scaffold beside it, and the known ones above are what is checked.
        if (!is_dir(self::SCAFFOLD)) {
            return;
        }
        $built = $this->dockerfilesTheScaffoldBuilds();
        self::assertContains('docker/e2e-runner/Dockerfile', $built, 'the runner is built from its own Dockerfile');
        foreach ($built as $dockerfile) {
            self::assertContains($dockerfile, $synced, "{$dockerfile} is built by a scaffold compose file but not synced into the release clone");
        }
        foreach ($this->composeFiles() as $compose) {
            self::assertContains($compose, $synced, "{$compose} is not synced into the release clone");
        }
    }

    /** @return list<string> the INFRA_FILES entries, comments dropped */
    private function syncedFiles(): array
    {
        $script = (string) file_get_contents(self::SCRIPT);
        self::assertSame(1, preg_match('/^INFRA_FILES=\(\n(.*?)^\)/ms', $script, $m), 'the script declares INFRA_FILES');
        $files = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $files[] = $line;
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function composeFiles(): array
    {
        return array_values(array_map('basename', glob(self::SCAFFOLD . '/docker-compose*.yml') ?: []));
    }

    /** @return list<string> project-relative Dockerfile paths the scaffold's compose files build */
    private function dockerfilesTheScaffoldBuilds(): array
    {
        $found = [];
        foreach (glob(self::SCAFFOLD . '/docker-compose*.yml') ?: [] as $compose) {
            $yaml = file_get_contents($compose);
            // A file this check cannot read is not a file with nothing to build (review, dev#220).
            self::assertIsString($yaml, "cannot read {$compose}");
            foreach (self::buildsIn($yaml) as $dockerfile) {
                $found[] = $dockerfile;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The Dockerfile each `build:` in a compose file builds, resolved as Compose
     * resolves it (review, dev#220): `build: <context>` builds <context>/Dockerfile;
     * a block's `dockerfile:` is relative to its `context:` (default ".").
     *
     * @return list<string>
     */
    private static function buildsIn(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $found = [];
        foreach ($lines as $i => $line) {
            // A trailing comment is not part of the value: "build: . # app" builds ".".
            if (preg_match('/^(\s+)build:\s*([^\s#]*)\s*(?:#.*)?$/', $line, $m) !== 1) {
                continue;
            }
            $context = trim($m[2], '"\'');
            $dockerfile = 'Dockerfile';
            if ($context === '') {
                $context = '.';
                $indent = strlen($m[1]);
                for ($j = $i + 1; $j < count($lines); $j++) {
                    if (trim($lines[$j]) === '' || str_starts_with(ltrim($lines[$j]), '#')) {
                        continue;
                    }
                    if (strlen($lines[$j]) - strlen(ltrim($lines[$j])) <= $indent) {
                        break;
                    }
                    if (preg_match('/^\s+context:\s*["\']?([^"\'\s]+)/', $lines[$j], $c) === 1) {
                        $context = $c[1];
                    }
                    if (preg_match('/^\s+dockerfile:\s*["\']?([^"\'\s]+)/', $lines[$j], $d) === 1) {
                        $dockerfile = $d[1];
                    }
                }
            }
            $found[] = self::normalise($context . '/' . $dockerfile);
        }

        return $found;
    }

    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    #[Test]
    public function a_build_resolves_its_dockerfile_against_its_context(): void
    {
        $yaml = "services:\n  app:\n    build: . # the app\n  worker:\n    build: ./docker/worker\n  runner:\n    build:\n      context: .\n      dockerfile: docker/e2e-runner/Dockerfile\n  tools:\n    build:\n      context: ./docker/tools\n      dockerfile: Dockerfile.dev\n  plain:\n    build:\n      context: docker/plain\n";

        self::assertSame(
            ['Dockerfile', 'docker/worker/Dockerfile', 'docker/e2e-runner/Dockerfile', 'docker/tools/Dockerfile.dev', 'docker/plain/Dockerfile'],
            self::buildsIn($yaml),
        );
    }

    /**
     * The CLI is required and always runnable (review, semitexa-dev#230): a
     * scaffold without it kept the clone's old CLI silently, and a CLI with the
     * same bytes but no execute bit was skipped before its chmod.
     */
    #[Test]
    public function the_cli_is_required_and_left_executable(): void
    {
        $root = sys_get_temp_dir() . '/semitexa-clone-cli-' . bin2hex(random_bytes(4));
        mkdir($root . '/scaffold/bin', 0777, true);
        mkdir($root . '/clone/bin', 0777, true);
        $run = function () use ($root): array {
            exec('RELEASE_ROOT=' . escapeshellarg($root . '/clone') . ' SEMITEXA_SCAFFOLD_DIR=' . escapeshellarg($root . '/scaffold')
                . ' bash ' . escapeshellarg((string) realpath(self::SCRIPT)) . ' 2>&1', $out, $status);

            return [$status, implode("\n", $out)];
        };

        [$status, $out] = $run();
        self::assertNotSame(0, $status, 'no CLI in the scaffold fails the stage');
        self::assertStringContainsString('the release clone would keep its old CLI', $out);

        file_put_contents($root . '/scaffold/bin/semitexa', "#!/bin/sh\necho cli\n");
        file_put_contents($root . '/clone/bin/semitexa', "#!/bin/sh\necho cli\n");
        chmod($root . '/clone/bin/semitexa', 0644);
        [$status, $out] = $run();
        clearstatcache();
        $mode = fileperms($root . '/clone/bin/semitexa') & 0111;
        exec('rm -rf ' . escapeshellarg($root));

        self::assertSame(0, $status, $out);
        self::assertNotSame(0, $mode, 'the same bytes, made executable all the same');
    }

    #[Test]
    public function the_image_marker_changes_when_the_runner_dockerfile_does(): void
    {
        // Review, dev#220: the marker hashed ./Dockerfile alone, so a changed runner
        // Dockerfile was copied in and the rebuild skipped.
        $script = (string) file_get_contents(self::SCRIPT);
        self::assertSame(1, preg_match('/^BUILT_DOCKERFILES=\((.*?)\)$/m', $script, $list));
        self::assertSame(1, preg_match('/^dockerfile_sha\(\) \{\n.*?^\}\n/ms', $script, $fn));
        // Whole entries, not substrings: "Dockerfile" is inside "docker/e2e-runner/Dockerfile".
        $marked = preg_split('/\s+/', trim($list[1])) ?: [];
        foreach ($this->syncedFiles() as $file) {
            if (basename($file) === 'Dockerfile') {
                self::assertContains($file, $marked, "{$file} is synced but not in the image marker");
            }
        }

        $root = sys_get_temp_dir() . '/semitexa-marker-' . bin2hex(random_bytes(4));
        mkdir($root . '/docker/e2e-runner', 0777, true);
        file_put_contents($root . '/Dockerfile', "FROM app\n");
        file_put_contents($root . '/docker/e2e-runner/Dockerfile', "FROM playwright\n");
        $sha = static fn (): string => (string) shell_exec('bash -c ' . escapeshellarg('RELEASE_ROOT=' . escapeshellarg($root) . "\n" . $list[0] . "\n" . $fn[0] . 'dockerfile_sha'));
        $before = $sha();
        file_put_contents($root . '/docker/e2e-runner/Dockerfile', "FROM playwright\nRUN npm install -g @axe-core/playwright\n");
        $after = $sha();
        exec('rm -rf ' . escapeshellarg($root));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', trim($before));
        self::assertNotSame($before, $after, 'a changed runner Dockerfile changes the marker, so the images are rebuilt');
    }
}
