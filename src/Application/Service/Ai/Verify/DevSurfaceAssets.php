<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Stylesheets and templates that only a dev surface shows: the Observatory's
 * and the Explorer's own, and the graph viewer's.
 *
 * The page check opens public pages in the e2e-runner. A dev surface answers
 * this machine alone (ObservatoryPanelGate), so the runner cannot open it,
 * and no public page loads its stylesheet. Planned like any other
 * stylesheet, graph-view.css picked eight project pages, each reported "does
 * not load graph-view.css: it cannot show that change", and the run stayed
 * incomplete with nothing anyone could do about it (2026-11-19).
 *
 * Such a file picks no page. It gets one target per surface, never required,
 * whose result names the E2E specs that open that surface: those are where
 * the change is looked at, and a changed spec among them is run by its own
 * target ({@see E2eSpecTargets}). That is a statement of where the check
 * lives, not a pass: the result is a skip. It says nothing was read here, so
 * it does not count the file as checked either: when no other target reads
 * it (lint:css-tokens reads a stylesheet), CoverageGap lists it as unchecked
 * and a change of dev-surface files alone ends incomplete. That is the
 * honest verdict for a change only a person or its spec looked at, and it
 * is left so on purpose.
 *
 * The directories are the ones the asset handlers serve from
 * (GraphViewerAssets::dir(), ObservatoryHtmlRenderer::assetDir(),
 * ExplorerHtmlRenderer::assetDir()); DevSurfaceAssetsTest holds the two
 * together. The toolbar is not here: it is drawn on the project's own pages.
 */
final class DevSurfaceAssets
{
    /** Directory inside a semitexa package => the surface that serves it. */
    public const SERVED = [
        'project-graph/resources/viewer/' => '/__observatory',
        'dev/resources/observatory/' => '/__observatory',
        'dev/resources/explorer/' => '/__explorer',
    ];

    /** The dev surface that alone shows this file, or null when a page may. */
    public static function surfaceOf(string $path): ?string
    {
        if (preg_match('#^(?:packages/semitexa-|vendor/semitexa/)(.+)$#', ltrim($path, '/'), $m) !== 1) {
            return null;
        }
        foreach (self::SERVED as $directory => $surface) {
            if (str_starts_with($m[1], $directory)) {
                return $surface;
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $bySurface surface => the changed files it alone shows
     * @return list<VerificationTarget>
     */
    public static function targets(array $bySurface): array
    {
        $targets = [];
        foreach ($bySurface as $surface => $files) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_DEV_SURFACE,
                id: "dev_surface:{$surface}",
                reason: 'shown only on a dev surface, which the page check cannot open: no public page is opened for it, and the E2E specs of that surface are named instead',
                triggeredBy: array_values(array_unique($files)),
                commandInput: ['path' => $surface],
            );
        }

        return $targets;
    }

    public static function check(VerificationTarget $target, string $projectRoot): VerificationResult
    {
        $surface = $target->commandInput['path'] ?? '';
        $surface = is_string($surface) ? $surface : '';
        $specs = $surface === '' ? [] : self::specsOpening($surface, rtrim($projectRoot, '/'));
        $files = implode(', ', array_map(basename(...), $target->triggeredBy));

        return new VerificationResult(
            $target,
            VerificationResult::STATUS_SKIPPED,
            0,
            "{$files}: shown only on {$surface}, a dev surface that answers this machine alone, so no public page was opened for it. "
                . ($specs === []
                    ? 'No E2E spec in this project opens that surface: look at it yourself, in light and dark.'
                    : 'Looked at by the E2E ' . (count($specs) === 1 ? 'spec that opens' : 'specs that open') . ' it: ' . implode(', ', $specs) . ' (bin/semitexa test:run --e2e=<spec>).'),
            required: false,
        );
    }

    /**
     * @return list<string> project-relative paths of the specs that open the surface
     */
    private static function specsOpening(string $surface, string $root): array
    {
        $specs = [];
        foreach (['packages/*', 'vendor/semitexa/*', 'src/modules/*'] as $owners) {
            foreach (glob("{$root}/{$owners}/tests/E2E/*.spec.ts") ?: [] as $spec) {
                $source = @file_get_contents($spec);
                // A spec that OPENS the surface (goto('/__observatory#graph')), not one that mentions it or opens a page under it.
                if (is_string($source) && preg_match('#goto\\(\\s*[\'"`]' . preg_quote($surface, '#') . '(?![A-Za-z0-9_/-])#', $source) === 1) {
                    // Once each: a package linked into vendor/ is the package, not a second spec.
                    $specs[realpath($spec) ?: $spec] ??= substr($spec, strlen($root) + 1);
                }
            }
        }
        $specs = array_values($specs);
        sort($specs);

        return $specs;
    }
}
