<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The documentation gates a change adds to a verification plan: docs:claims
 * for changed pages, docs:reference for changed PHP. Out of VerificationPlanner,
 * as the page and project gates already are ({@see PageCheckTargets},
 * {@see ProjectGuardTargets}).
 */
final class DocsTargets
{
    /**
     * Documentation gates.
     *
     * Two different failures, so two targets. A page can claim an attribute
     * that no longer exists — that is a markdown change, or a rename that left
     * the page behind. And a signature can change without the generated
     * reference being rebuilt, which no amount of reading the prose would
     * catch; only regenerating and comparing does.
     *
     * Both are skipped when semitexa/docs is absent, and the claim check runs
     * against a baseline when the project keeps one, so existing debt does not
     * block unrelated work.
     *
     * @param list<ChangedFile> $changedFiles
     *
     * @return list<VerificationTarget>
     */
    public static function of(array $changedFiles, string $projectRoot): array
    {
        // The files each gate reads, named: an empty triggeredBy made every
        // changed .md look unchecked to the coverage report.
        $markdown = [];
        $php = [];
        foreach ($changedFiles as $file) {
            if (str_ends_with(strtolower($file->path), '.md') && !ProjectGuardTargets::isInstruction($file->path)) {
                // Only a page that is there. A deleted one holds no claim, and
                // one that never existed (a typo in --files) would be skipped by
                // docs:lint, which then passes having read nothing; left out, it
                // stays unread in the coverage report instead of credited. With
                // nothing left to name, docs:lint would fall back to the corpus.
                if ($file->status !== ChangedFile::STATUS_DELETED && is_file(self::absolute($file->path, $projectRoot))) {
                    $markdown[] = $file->path;
                }
            } elseif (str_ends_with(strtolower($file->path), '.php')) {
                $php[] = $file->path;
            }
        }

        $targets = [];

        if ($markdown !== []) {
            // The changed pages, and only those. With no --path docs:lint reads
            // its own package's corpus, which in a project is vendor/semitexa/docs:
            // pages about graphql, cms and notifications, packages a project
            // built from ultimate does not have. Guard run 2026-11-08: a changed
            // README made verify red on 20 such claims nobody there could fix,
            // and the agent deleted its own client source to get past it.
            $input = ['--path' => array_map(static fn (string $path): string => self::absolute($path, $projectRoot), $markdown)];
            $baseline = $projectRoot . '/var/docs/docs-lint-baseline.json';
            if (is_file($baseline)) {
                $input['--baseline'] = $baseline;
            }

            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_DOCS,
                id: 'docs:claims',
                reason: 'a documented attribute, command or env key that the framework no longer has',
                triggeredBy: $markdown,
                commandName: 'docs:lint',
                commandInput: $input,
            );
        }

        if ($php !== []) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_DOCS,
                id: 'docs:reference',
                reason: 'the generated reference is built from signatures; changing one without rebuilding leaves the page wrong',
                triggeredBy: $php,
                commandName: 'docs:reference:generate',
                commandInput: ['--check' => true],
            );
        }

        return $targets;
    }

    private static function absolute(string $path, string $projectRoot): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($projectRoot, '/') . '/' . $path;
    }
}
