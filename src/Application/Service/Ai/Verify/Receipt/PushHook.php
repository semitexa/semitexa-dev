<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Receipt;

/**
 * The pre-push gate ai:verify:covers --install-hook writes (tk-prepush-refuses-red-verify).
 *
 * For each ref a push sends, the hook lists the commits no remote has yet
 * (`git rev-list <sha> --not --remotes`: a fast-forward to what is already on
 * GitHub sends nothing new and is not asked about) and asks ai:verify:covers
 * about them. A hook someone else wrote is never overwritten.
 */
final class PushHook
{
    public const MARKER = '# semitexa: pre-push receipt gate';
    public const WRITTEN = 'installed';
    public const UPDATED = 'updated';
    public const FOREIGN = 'left alone: a pre-push hook that is not ours is there';

    /**
     * @param array<string, string> $repositories workspace-relative prefix => absolute path
     * @return array<string, string> prefix => outcome
     */
    public function install(array $repositories): array
    {
        $outcomes = [];
        foreach ($repositories as $prefix => $path) {
            $hooks = $this->hooksDir($path);
            if ($hooks === null) {
                $outcomes[$prefix] = 'skipped: git names no hooks directory';
                continue;
            }
            $file = $hooks . '/pre-push';
            $existing = is_file($file) ? (string) file_get_contents($file) : null;
            if ($existing !== null && !str_contains($existing, self::MARKER)) {
                $outcomes[$prefix] = self::FOREIGN;
                continue;
            }
            if (!is_dir($hooks)) {
                @mkdir($hooks, 0o775, true);
            }
            file_put_contents($file, self::script($prefix));
            chmod($file, 0o755);
            $outcomes[$prefix] = $existing === null ? self::WRITTEN : self::UPDATED;
        }

        return $outcomes;
    }

    public static function script(string $prefix): string
    {
        // The hook runs on the host, the installer may run in a container: the project
        // root is found from the repository, not written as a path.
        $up = $prefix === '' ? '.' : rtrim(str_repeat('../', substr_count(trim($prefix, '/'), '/') + 1), '/');
        $root = '"$(git rev-parse --show-toplevel)/' . $up . '"';
        $repo = escapeshellarg($prefix);
        $this_marker = self::MARKER;

        return <<<SH
            #!/bin/sh
            {$this_marker}
            # Refuses a push when a commit it sends has no passing ai:verify receipt for its bytes.
            # Pushing anyway is the operator's call: git push --no-verify.
            zero=0000000000000000000000000000000000000000
            shas=""
            while read -r local_ref local_sha remote_ref remote_sha; do
                [ "\$local_sha" = "\$zero" ] && continue
                new=\$(git rev-list "\$local_sha" --not --remotes) || exit 1
                shas="\$shas \$new"
            done
            [ -z "\$(echo \$shas)" ] && exit 0
            cd {$root} && exec bin/semitexa ai:verify:covers --repo={$repo} \$shas
            SH;
    }

    private function hooksDir(string $repository): ?string
    {
        $proc = proc_open(['git', '-C', $repository, '-c', 'safe.directory=' . $repository, 'rev-parse', '--path-format=absolute', '--git-path', 'hooks'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);

        return proc_close($proc) === 0 && $out !== '' ? $out : null;
    }
}
