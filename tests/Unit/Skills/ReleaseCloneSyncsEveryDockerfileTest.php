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
            $yaml = (string) file_get_contents($compose);
            // "build: ." builds ./Dockerfile; a build block names its dockerfile.
            if (preg_match('/^\s+build:\s*\.\s*$/m', $yaml) === 1) {
                $found[] = 'Dockerfile';
            }
            preg_match_all('/^\s+dockerfile:\s*["\']?([^"\'\s]+)/m', $yaml, $named);
            foreach ($named[1] as $dockerfile) {
                $found[] = ltrim($dockerfile, './');
            }
        }

        return array_values(array_unique($found));
    }
}
