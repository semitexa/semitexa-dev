<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Presence;

/**
 * What is being edited in the workspace right now, whoever is editing it.
 *
 * The registry only knows agents that joined. This does not depend on that:
 * it reads each repository's uncommitted files and how recently they changed,
 * which is exactly the evidence an agent used to find by accident — a package
 * dirty with files it never touched, a commit sweeping someone else's edit in.
 * Now it is on the first screen, and each repo is matched to the live agent
 * that declared it, or marked unclaimed.
 */
final class WorkspaceActivity
{
    /** Edited this recently and a repo counts as being worked on now. */
    public const FRESH_SECONDS = 1800;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @param list<AgentSession> $live
     *
     * @return list<array{repo: string, dirty: int, last_edit: string, fresh: bool, sample: list<string>, claimed_by: list<string>}>
     */
    public function dirtyRepos(array $live = [], ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach ($this->repos() as $repo) {
            $lines = $this->porcelain($repo);
            if ($lines === []) {
                continue;
            }
            $newest = 0;
            $paths = [];
            foreach ($lines as $line) {
                // Paths with spaces or unusual bytes come back quoted.
                $path = trim(trim(substr($line, 3)), '"');
                if (str_contains($path, ' -> ')) {
                    $path = (string) substr($path, (int) strrpos($path, ' -> ') + 4);
                }
                $paths[] = $path;
                $abs = $this->projectRoot . '/' . $repo . '/' . $path;
                $newest = max($newest, (int) @filemtime($abs));
            }
            $claimedBy = [];
            foreach ($live as $session) {
                foreach ($session->repos as $declared) {
                    if (rtrim($declared, '/') === $repo) {
                        $claimedBy[] = $session->id;
                    }
                }
            }
            $out[] = [
                'repo' => $repo,
                'dirty' => count($lines),
                'last_edit' => $newest > 0 ? gmdate('c', $newest) : '',
                'fresh' => $newest > 0 && $now - $newest <= self::FRESH_SECONDS,
                'sample' => array_slice($paths, 0, 3),
                'claimed_by' => $claimedBy,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['last_edit'], $a['last_edit']));

        return $out;
    }

    /**
     * Whether the edits above can be seen at all. Without git every repo reads
     * as clean, which is exactly the false all-clear this class exists to end —
     * so a caller says "unknown" instead.
     */
    public function gitAvailable(): bool
    {
        return trim((string) shell_exec('command -v git 2>/dev/null')) !== '';
    }

    /**
     * @return list<string> repos relative to the project root
     */
    private function repos(): array
    {
        $repos = [];
        foreach (glob($this->projectRoot . '/packages/semitexa-*/.git') ?: [] as $git) {
            $repos[] = 'packages/' . basename(dirname($git));
        }
        if (is_dir($this->projectRoot . '/src/.git')) {
            $repos[] = 'src';
        }
        if (is_dir($this->projectRoot . '/.git')) {
            $repos[] = '.';
        }

        return $repos;
    }

    /**
     * @return list<string>
     */
    private function porcelain(string $repo): array
    {
        // safe.directory: the workspace is bind-mounted and owned by the host
        // user, so git inside the container refuses it as "dubious ownership"
        // and answers nothing — which would read as a clean workspace.
        // --no-optional-locks: a read must not take index.lock, or another
        // agent's `git add` / `git commit` in the same repo fails under it.
        $command = sprintf(
            'git --no-optional-locks -c safe.directory=%s -C %s status --porcelain 2>/dev/null',
            escapeshellarg('*'),
            escapeshellarg($this->projectRoot . '/' . $repo),
        );
        $output = shell_exec($command);

        return is_string($output) ? array_values(array_filter(explode("\n", $output), static fn (string $l): bool => trim($l) !== '')) : [];
    }
}
