<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Receipt;

/**
 * Which passing ai:verify run vouches for a commit (tk-prepush-refuses-red-verify).
 *
 * "Verify every write" was a sentence, and on 2026-11-12 a red run was pushed for
 * the third time: a command chain printed the verdict and committed anyway. A push
 * gate asks this instead: for each commit about to leave the machine, is there a
 * receipt with verdict pass whose run checked every file the commit adds or
 * changes, with those bytes? Only the commit's own repository counts, so a run over
 * two repositories vouches for each one's commit.
 */
final readonly class ReceiptForCommit
{
    /** Receipts kept by VerifyReceipts; newest first is the likely match. */
    private const SCAN = 200;

    public function __construct(private string $projectRoot) {}

    /**
     * @param string $repository workspace-relative repository ('' for the project root, 'packages/semitexa-dev')
     * @return string|null the id of a passing receipt that covers the commit
     */
    public function find(string $repository, string $sha): ?string
    {
        $prefix = $repository === '' ? '' : rtrim($repository, '/') . '/';
        foreach ($this->passingTrees() as $id => $tree) {
            $own = [];
            foreach ($tree as $path => $hash) {
                if ($prefix === '' ? !str_starts_with($path, 'packages/') : str_starts_with($path, $prefix)) {
                    $own[$path] = $hash;
                }
            }
            if ($own === []) {
                continue;
            }
            $coverage = (new CommitCoverage($this->projectRoot))->of($own, $sha);
            if ($coverage['commits'] !== [] && $coverage['unresolved'] === [] && $coverage['unchecked'] === [] && $coverage['differs'] === []) {
                return $id;
            }
        }

        return null;
    }

    /** @return iterable<string, array<string, string>> receipt id => its tree, newest first, passing runs only */
    private function passingTrees(): iterable
    {
        $files = glob(rtrim($this->projectRoot, '/') . '/' . VerifyReceipts::DIR . '/rcpt-*.json') ?: [];
        rsort($files);
        foreach (array_slice($files, 0, self::SCAN) as $file) {
            $raw = @file_get_contents($file);
            $receipt = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($receipt) || ($receipt['verdict'] ?? null) !== 'pass' || !is_array($receipt['tree'] ?? null) || !is_string($receipt['id'] ?? null)) {
                continue;
            }
            $tree = array_filter($receipt['tree'], static fn ($hash): bool => is_string($hash));
            yield $receipt['id'] => $tree;
        }
    }
}
