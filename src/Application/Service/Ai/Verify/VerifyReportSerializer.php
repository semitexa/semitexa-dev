<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * One verification run, in the shapes a reader consumes it in.
 *
 * `ai:verify` answers in two: a single `--json` envelope and the default
 * NDJSON stream. Both are built from the same targets and results, and while
 * the two builders sat side by side inside the command every addition had to
 * be made twice -- the restart advice and the dirty-workspace scan each
 * reached one shape and not the other, and both were found in review rather
 * than by anyone reading the output. Here there is one definition per fact.
 *
 * Pure: handed results, returns arrays. Nothing here reads the filesystem,
 * dispatches a command, or decides an exit code.
 */
final readonly class VerifyReportSerializer
{
    /** @param list<VerificationResult> $results */
    public function completed(array $results): bool
    {
        if ($results === []) {
            return false;
        }
        foreach ($results as $result) {
            if ($result->required && !$result->completed()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a server restart is needed, and for what.
     *
     * Verification never needs one. Every check here runs in a fresh CLI
     * process that rediscovers classes from disk, so a file saved a second ago
     * is already visible — measured by having a brand-new class fail this
     * command with no restart in between.
     *
     * Saying so matters because the habit is expensive and invisible. A
     * consumer agent reported a 30-60s verify cycle as its single biggest time
     * sink, having wrapped every run in `server:restart` + `cache:clear`. On
     * this machine that is 16s of restart around 4s of actual verification —
     * four fifths of the cycle spent on a step that changed nothing. Nothing in
     * the output contradicted the assumption, so it never got questioned.
     *
     * A restart IS needed before exercising the RUNNING server, because Swoole
     * workers hold discovered classes and compiled templates for the life of
     * the worker. That is a different activity from verifying, and the advice
     * only appears when the changed files are the kind the running server
     * caches.
     *
     * @param list<ChangedFile> $changedFiles
     * @return array{needed_for_verification: bool, note: string, before_browsing?: string}
     */
    public function restartAdvice(array $changedFiles): array
    {
        $advice = [
            'needed_for_verification' => false,
            'note' => 'Verification reads files from disk in a fresh process; '
                . 'no restart was needed and none would have changed this result.',
        ];

        $affectsRunningServer = false;
        foreach ($changedFiles as $file) {
            if (in_array($file->kind, [
                ChangedFile::KIND_TEMPLATE,
                ChangedFile::KIND_CLIENT_SCRIPT,
            ], true) || str_ends_with($file->path, '.php')) {
                $affectsRunningServer = true;
                break;
            }
        }

        if ($affectsRunningServer) {
            $advice['before_browsing'] = 'bin/semitexa server:restart — required only before exercising '
                . 'the running server (browser, curl, E2E): workers cache discovered classes and '
                . 'compiled templates for their lifetime.';
        }

        return $advice;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTarget(VerificationTarget $target): array
    {
        return [
            'id'           => $target->id,
            'type'         => $target->type,
            'reason'       => $target->reason,
            'triggered_by' => $target->triggeredBy,
            'command_name' => $target->commandName,
            'file_path'    => $target->filePath,
            'test_filter'  => $target->testFilter,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeResult(VerificationResult $result): array
    {
        $out = [
            'id'        => $result->target->id,
            'type'      => $result->target->type,
            'status'    => $result->status,
            'exit_code' => $result->exitCode,
            'signal'    => $result->signal,
            'required'  => $result->required,
            'completed' => $result->completed(),
        ];
        if ($result->diagnostics !== []) {
            $out['diagnostics_count'] = count($result->diagnostics);
        }
        if ($result->accepted !== []) {
            // In full, with their reasons. A count and the first path in the
            // signal line told a reader that something was excused without ever
            // saying what or why.
            $out['accepted'] = $result->accepted;
        }
        return $out;
    }

    /**
     * @param list<VerificationResult> $results
     * @return array<string, int>
     */
    public function countByStatus(array $results): array
    {
        $counts = ['pass' => 0, 'fail' => 0, 'skipped' => 0, 'incomplete' => 0];
        foreach ($results as $r) {
            $counts[$r->status] = ($counts[$r->status] ?? 0) + 1;
        }
        return $counts;
    }
}
