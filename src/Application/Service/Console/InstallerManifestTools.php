<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Console;

/**
 * semitexa/installer's scaffold manifest, as scaffold:sync checks and keeps it.
 *
 * From step 2 of one source for bin/semitexa (dec-one-source-for-bin-semitexa),
 * semitexa/update reads the scaffold and this manifest from
 * vendor/semitexa/installer, so the installer's own tools answer for it:
 *
 *  - tools/check-manifest.php: the manifest describes scaffold/ in a form every
 *    released update reads, and still knows every hash the manifest at the
 *    installer's last release tag knew (Fable's review of the step 2 plan, D2
 *    and D3);
 *  - tools/derive-manifest.php, while update still ships the runtime entries
 *    itself: it copies them, so this manifest says what update's says. Once
 *    update's manifest lists none of the installer's files there is nothing to
 *    copy from, and the tool goes away (step 2d).
 *
 * The release stage installer-manifest-fresh runs the same two checks on the
 * host; this is the same question asked at verify time, from the PR that
 * changes the scaffold.
 */
final class InstallerManifestTools
{
    private const INSTALLER = 'packages/semitexa-installer';
    private const UPDATE = 'packages/semitexa-update';

    public function __construct(private readonly string $root)
    {
    }

    /**
     * What to run: in check mode both checks, in write mode the derive tool.
     *
     * Whatever cannot be read throws: the check tool missing, update's manifest
     * unreadable. A gate that cannot read what it checks must fail, not skip it
     * (review, dev#291).
     *
     * @param ?string $previous an earlier manifest to check the history against (check mode)
     * @return list<array{label: string, argv: list<string>}>
     * @throws \RuntimeException when a tool or update's manifest cannot be read
     */
    public function commands(bool $check, ?string $previous = null): array
    {
        $commands = [];
        if ($this->updateShipsInstallerFiles()) {
            $derive = $this->tool('derive-manifest.php');
            if ($derive === null) {
                throw new \RuntimeException('semitexa/update still ships the runtime entries, and ' . self::INSTALLER . '/tools/derive-manifest.php is missing.');
            }
            $commands[] = $check
                ? ['label' => 'derive-manifest.php --check', 'argv' => [$derive, '--check']]
                : ['label' => 'derive-manifest.php', 'argv' => [$derive]];
        }
        if ($check) {
            $checkTool = $this->tool('check-manifest.php');
            if ($checkTool === null) {
                throw new \RuntimeException(self::INSTALLER . '/tools/check-manifest.php is missing: the manifest semitexa/update reads cannot be checked.');
            }
            $commands[] = [
                'label' => 'check-manifest.php',
                'argv' => $previous !== null ? [$checkTool, '--previous=' . $previous] : [$checkTool],
            ];
        }

        return $commands;
    }

    /**
     * True while semitexa/update's own manifest still lists a file of the
     * installer's scaffold; false when it lists none, or there is no update
     * package to compare with.
     *
     * @throws \RuntimeException when update's manifest cannot be read as one
     */
    public function updateShipsInstallerFiles(): bool
    {
        if (!is_dir($this->root . '/' . self::UPDATE)) {
            return false;
        }
        $path = $this->root . '/' . self::UPDATE . '/resources/scaffold-manifest.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            throw new \RuntimeException("Cannot read semitexa/update's manifest as a scaffold manifest: {$path}");
        }
        foreach ($manifest['files'] as $index => $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || $entry['path'] === '') {
                throw new \RuntimeException("files[{$index}] of {$path} has no path.");
            }
            if (is_file($this->root . '/' . self::INSTALLER . '/scaffold/' . $entry['path'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes the manifest at the installer's newest release tag to $file.
     *
     * Tags sit on master's merge commits, which develop does not contain, so the
     * newest is found by version, not by `git describe`. No release tag, or a tag
     * from before the installer had a manifest, is an answer; git failing to
     * answer is not.
     *
     * @return ?string the tag, or null when there is none or it holds no manifest
     * @throws \RuntimeException when git cannot answer, or the manifest cannot be written
     */
    public function writeManifestAtLastTag(string $file): ?string
    {
        $dir = $this->root . '/' . self::INSTALLER;
        [$exit, $tags] = $this->process(['git', '-C', $dir, 'tag', '-l', '20*', '--sort=-version:refname']);
        if ($exit !== 0) {
            throw new \RuntimeException("git could not list the release tags of {$dir} (exit {$exit}): " . trim($tags));
        }
        $tag = trim(explode("\n", $tags)[0]);
        if ($tag === '') {
            return null;
        }
        [$exit, $listed] = $this->process(['git', '-C', $dir, 'ls-tree', '--name-only', $tag, '--', 'resources/scaffold-manifest.json']);
        if ($exit !== 0) {
            throw new \RuntimeException("git could not read tag {$tag} of {$dir} (exit {$exit}): " . trim($listed));
        }
        if (trim($listed) === '') {
            return null;
        }
        [$exit, $manifest] = $this->process(['git', '-C', $dir, 'show', $tag . ':resources/scaffold-manifest.json'], mergeErrors: false);
        if ($exit !== 0) {
            throw new \RuntimeException("git could not read the manifest at {$tag} (exit {$exit}).");
        }
        if (file_put_contents($file, $manifest) === false) {
            throw new \RuntimeException("Cannot write the manifest at {$tag} to {$file}.");
        }

        return $tag;
    }

    /**
     * Runs an installer tool with this PHP.
     *
     * @param list<string> $argv the tool and its arguments
     * @return array{int, string} the exit code, and stdout and stderr together
     */
    public function run(array $argv): array
    {
        return $this->process([PHP_BINARY, ...$argv]);
    }

    private function tool(string $name): ?string
    {
        $path = $this->root . '/' . self::INSTALLER . '/tools/' . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * @param list<string> $command
     * @return array{int, string}
     */
    private function process(array $command, bool $mergeErrors = true): array
    {
        // Both streams appended to one file keep their order, and a file cannot
        // fill up and stall the tool the way an unread pipe can.
        $file = tempnam(sys_get_temp_dir(), 'installer-tool-');
        if ($file === false) {
            return [127, 'could not create a file for the output of ' . $command[0]];
        }
        try {
            $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $file, 'a'], 2 => ['file', $mergeErrors ? $file : '/dev/null', 'a']];
            $process = proc_open($command, $descriptors, $pipes);
            if (!is_resource($process)) {
                return [127, 'could not start ' . $command[0]];
            }
            $exit = proc_close($process);
            $output = file_get_contents($file);

            return [$exit, is_string($output) ? $output : ''];
        } finally {
            @unlink($file);
        }
    }
}
