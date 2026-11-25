<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Console;

/**
 * semitexa/installer's scaffold manifest, as scaffold:sync checks it.
 *
 * From step 2 of one source for bin/semitexa (dec-one-source-for-bin-semitexa),
 * semitexa/update reads the scaffold and this manifest from
 * vendor/semitexa/installer, so the installer's own tool answers for it:
 * tools/check-manifest.php says the manifest describes scaffold/ in a form
 * every released update reads, and still knows every hash the manifest at the
 * installer's last release tag knew (Fable's review of the step 2 plan, D2 and
 * D3). update:scaffold:rebuild writes it.
 *
 * The release stage installer-manifest-fresh runs the same check on the host;
 * this is the same question asked at verify time, from the PR that changes the
 * scaffold.
 */
final class InstallerManifestTools
{
    private const INSTALLER = 'packages/semitexa-installer';

    public function __construct(private readonly string $root)
    {
    }

    /**
     * The check to run, against $previous when there is one.
     *
     * A missing check tool throws: a gate that cannot read what it checks must
     * fail, not skip it (review, dev#291).
     *
     * @param ?string $previous an earlier manifest to check the history against
     * @return array{label: string, argv: list<string>}
     * @throws \RuntimeException when the check tool is missing
     */
    public function checkCommand(?string $previous = null): array
    {
        $checkTool = $this->tool('check-manifest.php');
        if ($checkTool === null) {
            throw new \RuntimeException(self::INSTALLER . '/tools/check-manifest.php is missing: the manifest semitexa/update reads cannot be checked.');
        }

        return [
            'label' => 'check-manifest.php',
            'argv' => $previous !== null ? [$checkTool, '--previous=' . $previous] : [$checkTool],
        ];
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
