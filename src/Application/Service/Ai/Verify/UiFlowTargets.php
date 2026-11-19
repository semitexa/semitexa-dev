<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Plans the UI flow warning ({@see UiFlowCheck}) when a template, a payload
 * or a handler changed: the three places a link, a form or the route it
 * means is written. A deleted one counts, and so does the path a file was
 * renamed from, since removing a route is how a link elsewhere goes dead.
 *
 * Its own target, not a row of the planner's lint map: those are console
 * commands whose non-zero exit fails the run, and this may never fail it. No
 * console command is named `lint:ui-flow`: a new lint command would be a
 * second door beside ai:verify (VerifyLintSetIsDeclaredTest).
 *
 * With the lints, so not at minimal scope: it reads the project graph.
 */
final class UiFlowTargets
{
    public const ID = 'lint:ui-flow';

    /** A change of one of these can open or close a way through the UI. */
    private const KINDS = [ChangedFile::KIND_TEMPLATE, ChangedFile::KIND_PAYLOAD, ChangedFile::KIND_HANDLER];

    /**
     * @param list<ChangedFile> $files
     * @return list<VerificationTarget>
     */
    public static function of(array $files, string $effectiveScope): array
    {
        if ($effectiveScope === VerificationPlan::SCOPE_MINIMAL) {
            return [];
        }
        $triggeredBy = [];
        $classifier = null;
        foreach ($files as $file) {
            if (in_array($file->kind, self::KINDS, true)) {
                $triggeredBy[] = $file->path;
            }
            // A rename is a deletion of the old path too, and only the new one
            // was classified: a template renamed to notes.txt removed its
            // forms and planned nothing. Both paths, so a move between
            // modules reports both.
            if ($file->originalPath !== null && $file->originalPath !== '' && $file->originalPath !== $file->path) {
                $classifier ??= new ChangedFileClassifier();
                if (in_array($classifier->classify($file->originalPath, ChangedFile::STATUS_DELETED)->kind, self::KINDS, true)) {
                    $triggeredBy[] = $file->originalPath;
                }
            }
        }
        if ($triggeredBy === []) {
            return [];
        }

        return [new VerificationTarget(
            type: VerificationTarget::TYPE_UI_FLOW,
            id: self::ID,
            reason: 'a template, payload or handler changed: links and forms to no route, and POST pages no form reaches, in the modules touched (warnings only)',
            triggeredBy: array_values(array_unique($triggeredBy)),
        )];
    }
}
