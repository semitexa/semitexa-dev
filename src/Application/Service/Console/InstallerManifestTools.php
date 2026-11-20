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
    private const UPDATE_MANIFEST = 'packages/semitexa-update/resources/scaffold-manifest.json';

    public function __construct(private readonly string $root)
    {
    }

    /**
     * What to run: in check mode both checks, in write mode the derive tool.
     *
     * @param ?string $previous an earlier manifest to check the history against (check mode)
     * @return list<array{label: string, argv: list<string>}>
     */
    public function commands(bool $check, ?string $previous = null): array
    {
        $commands = [];
        $derive = $this->tool('derive-manifest.php');
        if ($derive !== null && $this->updateShipsInstallerFiles()) {
            $commands[] = $check
                ? ['label' => 'derive-manifest.php --check', 'argv' => [$derive, '--check']]
                : ['label' => 'derive-manifest.php', 'argv' => [$derive]];
        }
        $checkTool = $this->tool('check-manifest.php');
        if ($check && $checkTool !== null) {
            $commands[] = [
                'label' => 'check-manifest.php',
                'argv' => $previous !== null ? [$checkTool, '--previous=' . $previous] : [$checkTool],
            ];
        }

        return $commands;
    }

    /** True while semitexa/update's own manifest still lists a file of the installer's scaffold. */
    public function updateShipsInstallerFiles(): bool
    {
        $raw = @file_get_contents($this->root . '/' . self::UPDATE_MANIFEST);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            return false;
        }
        foreach ($manifest['files'] as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null) && $entry['path'] !== ''
                && is_file($this->root . '/' . self::INSTALLER . '/scaffold/' . $entry['path'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes the manifest at the installer's newest release tag to $file.
     *
     * Tags sit on master's merge commits, which develop does not contain, so the
     * newest is found by version, not by `git describe`.
     *
     * @return ?string the tag, or null when there is none or it holds no manifest
     */
    public function writeManifestAtLastTag(string $file): ?string
    {
        $dir = $this->root . '/' . self::INSTALLER;
        [$exit, $tags] = $this->process(['git', '-C', $dir, 'tag', '-l', '20*', '--sort=-version:refname']);
        $tag = $exit === 0 ? trim(strtok($tags, "\n") ?: '') : '';
        if ($tag === '') {
            return null;
        }
        [$exit, $manifest] = $this->process(['git', '-C', $dir, 'show', $tag . ':resources/scaffold-manifest.json'], mergeErrors: false);
        if ($exit !== 0 || file_put_contents($file, $manifest) === false) {
            return null;
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
