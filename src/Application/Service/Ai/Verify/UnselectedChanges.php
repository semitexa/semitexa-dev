<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Symfony\Component\Console\Input\InputInterface;

/**
 * The uncommitted changes beside a run that it was not asked about.
 *
 * `ai:verify --files=A` checks A, and its receipt held "for the tree in front
 * of you" while B, changed in the same repository, was never read: measured
 * 2026-11-02 with a file reading an undefined variable left next to the
 * selection, the verdict was `pass` and the receipt said the claim held. The
 * selection is the caller's to make (a shared checkout holds other agents'
 * work, and committing a subset is legitimate), so this does not fail a run.
 * It names what the claim does not cover, where the claim is made: in the
 * verdict's headline and in the receipt check.
 *
 * Only the repositories the selection touches are asked: a change in another
 * package is no part of a commit made here.
 */
final readonly class UnselectedChanges
{
    /**
     * What a run left beside its selection. A selection is --files or
     * --diff-stdin; --dirty selects every change and --git-ref a range, so
     * neither leaves anything out.
     *
     * @return list<array{path: string, status: string}>
     */
    public static function ofRun(InputInterface $input, string $projectRoot, VerificationPlan $plan): array
    {
        $selection = ((array) $input->getOption('files') !== [] || (bool) $input->getOption('diff-stdin')) && !(bool) $input->getOption('dirty');

        return $selection ? self::beside($projectRoot, array_map(static fn (ChangedFile $f): string => $f->path, $plan->changedFiles)) : [];
    }

    /**
     * @param list<string> $selected workspace-relative paths the run checked (a directory covers what is under it)
     * @return list<array{path: string, status: string}>
     */
    public static function beside(string $projectRoot, array $selected): array
    {
        if ($selected === []) {
            return [];
        }
        $scanner = new DirtyWorkspaceScanner($projectRoot);
        $repositories = array_keys($scanner->repositories());
        $touched = [];
        foreach ($selected as $path) {
            $repo = self::repositoryOf($path, $repositories);
            if ($repo !== null) {
                $touched[$repo] = true;
            }
        }
        if ($touched === []) {
            return [];
        }

        $unselected = [];
        foreach ($scanner->changedFiles() as $change) {
            $repo = self::repositoryOf($change['path'], $repositories);
            if ($repo === null || !isset($touched[$repo]) || self::covered($change['path'], $selected)) {
                continue;
            }
            $unselected[] = ['path' => $change['path'], 'status' => $change['status']];
        }
        usort($unselected, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $unselected;
    }

    /**
     * The coverage a verdict states (CoverageGap::toArray()), with the changes
     * beside the selection that it does not cover.
     *
     * @param array{headline: string, unchecked_files: list<array{path: string, kind: string}>} $gap
     * @param list<array{path: string, status: string}> $unselected
     * @return array{headline: string, unchecked_files: list<array{path: string, kind: string}>, unselected_changes: list<array{path: string, status: string}>}
     */
    public static function annotate(array $gap, array $unselected): array
    {
        $gap['headline'] .= self::clause($unselected);
        $gap['unselected_changes'] = $unselected;

        return $gap;
    }

    /**
     * The clause a headline gains: empty when nothing was left out.
     *
     * @param list<array{path: string, status: string}> $unselected
     */
    public static function clause(array $unselected): string
    {
        if ($unselected === []) {
            return '';
        }

        return sprintf(
            '; %d other uncommitted change%s in the same repositor%s %s not selected, so no check read %s: %s',
            count($unselected),
            count($unselected) === 1 ? '' : 's',
            count($unselected) === 1 ? 'y' : 'ies',
            count($unselected) === 1 ? 'was' : 'were',
            count($unselected) === 1 ? 'it' : 'them',
            implode(', ', array_map(static fn (array $c): string => $c['path'], $unselected)),
        );
    }

    /**
     * The repository a path lives in: the longest repository prefix it sits
     * under, '' for the project root when that is one; null when none holds it.
     *
     * @param list<string> $repositories
     */
    private static function repositoryOf(string $path, array $repositories): ?string
    {
        $path = ltrim($path, '/');
        $best = null;
        foreach ($repositories as $prefix) {
            $holds = $prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/');
            if ($holds && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best;
    }

    /** @param list<string> $selected */
    private static function covered(string $path, array $selected): bool
    {
        foreach ($selected as $chosen) {
            $chosen = rtrim(ltrim($chosen, '/'), '/');
            if ($path === $chosen || str_starts_with($path, $chosen . '/')) {
                return true;
            }
        }

        return false;
    }
}
