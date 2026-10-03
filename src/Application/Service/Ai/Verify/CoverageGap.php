<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The changed files no check read.
 *
 * A verdict says how the checks that RAN went. It never said which changed
 * files no check looked at, so a pass quoted in a PR could not be falsified:
 * measured 2026-10-01, a change of a .md page, a .sh script and a .js file
 * planned only module_structure for them — a check of WHERE a file sits, not
 * of what it says — and reported `pass`.
 *
 * A file counts as checked when a target that reads its content selected it
 * AND ran to a pass or a fail: a planned check skipped because its command is
 * absent (docs:lint without semitexa/docs) read nothing. The structural targets
 * below select every file and read none of them, and lint:var-artifacts reads
 * a path's git status, not its content. A
 * DIRECTORY (`--all`, or `--files=packages/x`) is a request about structure,
 * so for it the structural check is the content check, and it is not counted.
 */
final readonly class CoverageGap
{
    private const STRUCTURAL_ONLY = [
        VerificationTarget::TYPE_MODULE_STRUCTURE,
        VerificationTarget::TYPE_CAPABILITY_INDEX,
    ];

    /** Targets that decide by a path, never by what the file says. */
    private const PATH_ONLY_IDS = ['lint:var-artifacts'];

    /**
     * @param list<ChangedFile> $unchecked
     */
    private function __construct(
        public array $unchecked,
        private int $changedCount,
        private int $targetCount,
    ) {}

    /**
     * @param list<VerificationResult> $results what the planned targets did when they ran
     */
    public static function of(VerificationPlan $plan, array $results, string $projectRoot): self
    {
        $checked = [];
        foreach ($results as $result) {
            $target = $result->target;
            if (!$result->completed() || in_array($target->type, self::STRUCTURAL_ONLY, true) || in_array($target->id, self::PATH_ONLY_IDS, true)) {
                continue;
            }
            foreach ($target->triggeredBy as $path) {
                $checked[$path] = true;
            }
        }

        $unchecked = [];
        $changed = 0;
        foreach ($plan->changedFiles as $file) {
            // A deleted file has no content left to read; a directory, see above.
            if ($file->status === ChangedFile::STATUS_DELETED || is_dir(rtrim($projectRoot, '/') . '/' . $file->path)) {
                continue;
            }
            ++$changed;
            if (!isset($checked[$file->path])) {
                $unchecked[] = $file;
            }
        }

        return new self($unchecked, $changed, count($plan->targets));
    }

    /**
     * A pass over a change no check could read is not evidence of anything:
     * the run is incomplete, and says why in `headline`.
     */
    public function adjust(string $verdict): string
    {
        $readNothing = $this->changedCount > 0 && count($this->unchecked) === $this->changedCount;

        return $readNothing && in_array($verdict, [VerificationResult::STATUS_PASS, VerificationResult::STATUS_SKIPPED], true)
            ? VerificationResult::STATUS_INCOMPLETE
            : $verdict;
    }

    /** One line a person can quote, and check, in a PR. */
    public function headline(): string
    {
        $line = sprintf(
            '%d check%s over %d changed file%s',
            $this->targetCount,
            $this->targetCount === 1 ? '' : 's',
            $this->changedCount,
            $this->changedCount === 1 ? '' : 's',
        );
        if ($this->unchecked === []) {
            return $line . '; every file was read by at least one check';
        }

        return sprintf(
            '%s; no check read %d of them: %s',
            $line,
            count($this->unchecked),
            implode(', ', array_map(static fn (ChangedFile $f): string => $f->path, $this->unchecked)),
        );
    }

    /**
     * @return array{headline: string, unchecked_files: list<array{path: string, kind: string}>}
     */
    public function toArray(): array
    {
        return [
            'headline' => $this->headline(),
            'unchecked_files' => array_map(
                static fn (ChangedFile $f): array => ['path' => $f->path, 'kind' => $f->kind],
                $this->unchecked,
            ),
        ];
    }
}
