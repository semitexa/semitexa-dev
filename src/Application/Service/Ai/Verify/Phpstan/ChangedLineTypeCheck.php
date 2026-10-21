<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Phpstan;

use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\ShellProcessRunner;

/**
 * PHPStan at level 5 on the changed files, reporting what lands on a line the
 * change touched: the type errors this change made.
 *
 * phpstan_di runs at level 0, which checks no argument types. A call
 * `StaticLoggerBridge::warning($message, $context)` that left out the channel
 * — a TypeError on the very path that was meant only to warn — passed it and
 * was committed (2026-10-21). Level 5 reports it, and so does it report every
 * older error in the same file; the line filter keeps the gate about this
 * change, so touching a file does not mean owning its history.
 *
 * A changed declaration breaks lines that did not change: its callers. So a
 * diagnostic anywhere in the analysed files is kept too when its message
 * names a method, property or constant declared on a changed line
 * (`::send()`, `::$queue`, `::LIMIT`). Callers in files that are not analysed
 * stay unseen.
 *
 * Changed lines come from `git diff -U0 HEAD` in the file's own repository.
 * A file git does not track is new: all of it changed. So is one whose
 * changes git cannot tell (outside any repository, a failed diff), and a
 * renamed one (the diff cannot pair it): a gate that cannot narrow reports
 * too much rather than nothing. Semitexa's own rules, and what level 0
 * already reports, are left to phpstan_di.
 */
final class ChangedLineTypeCheck
{
    public const LEVEL = 5;

    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner = new ShellProcessRunner(),
        private readonly ?PhpstanRunner $phpstan = null,
    ) {}

    /** @param list<string> $relativePaths */
    public function run(array $relativePaths): PhpstanRunResult
    {
        if ($relativePaths === []) {
            return new PhpstanRunResult(PhpstanRunResult::STATUS_PASS, [], 'phpstan_types → no files');
        }

        // Keyed as PHPStan reports a path: `./a`, `a//b` and `a/../b` are
        // `a`, `a/b` and `b` there, and a key that does not match drops every
        // diagnostic of the file — a silent pass.
        $lines = [];
        $names = [];
        foreach ($relativePaths as $path) {
            $key = self::normalise($path);
            $lines[$key] = $this->changedLines($key);
            array_push($names, ...$this->declaredOn($key, $lines[$key]));
        }

        $result = ($this->phpstan ?? new PhpstanRunner($this->projectRoot, $this->processRunner, level: self::LEVEL, check: 'phpstan_types'))
            ->run(array_keys($lines));

        $mine = static function (array $d) use ($lines, $names): bool {
            $path = self::normalise((string) ($d['path'] ?? ''));
            if (str_starts_with((string) ($d['identifier'] ?? ''), 'semitexa.') || !array_key_exists($path, $lines)) {
                return false;
            }
            if ($lines[$path] === null || isset($lines[$path][(int) ($d['line'] ?? 0)])) {
                return true;
            }
            $message = (string) ($d['message'] ?? '');
            foreach ($names as $name) {
                if (str_contains($message, $name)) {
                    return true;
                }
            }

            return false;
        };
        $diagnostics = array_values(array_filter($result->diagnostics, $mine));
        $accepted = array_values(array_filter($result->accepted, $mine));

        if ($result->status === PhpstanRunResult::STATUS_ERROR) {
            return new PhpstanRunResult($result->status, $diagnostics, $result->rawSignal, $result->exitCode, $accepted);
        }

        return new PhpstanRunResult(
            status: $diagnostics === [] ? PhpstanRunResult::STATUS_PASS : PhpstanRunResult::STATUS_FAIL,
            diagnostics: $diagnostics,
            rawSignal: $diagnostics === []
                ? 'phpstan_types → 0 errors on changed lines'
                : sprintf(
                    'phpstan_types → %d error(s) on changed lines; first: %s %s:%d %s',
                    count($diagnostics),
                    $diagnostics[0]['identifier'] ?? 'phpstan.error',
                    $diagnostics[0]['path'] ?? '?',
                    (int) ($diagnostics[0]['line'] ?? 0),
                    $diagnostics[0]['message'] ?? '',
                ),
            exitCode: $diagnostics === [] ? 0 : 1,
            accepted: $accepted,
        );
    }

    /**
     * The DI run and this one as one result: the worse status, both signals,
     * every diagnostic once — what level 0 reports, level 5 reports again.
     */
    public static function merge(PhpstanRunResult $di, PhpstanRunResult $types): PhpstanRunResult
    {
        $rank = [PhpstanRunResult::STATUS_PASS => 0, PhpstanRunResult::STATUS_SKIPPED => 0, PhpstanRunResult::STATUS_FAIL => 1, PhpstanRunResult::STATUS_ERROR => 2];

        $seen = [];
        foreach ($di->diagnostics as $d) {
            $seen[self::site($d)] = true;
        }
        $extra = array_values(array_filter($types->diagnostics, static fn (array $d): bool => !isset($seen[self::site($d)])));
        $typesStatus = $types->status === PhpstanRunResult::STATUS_FAIL && $extra === [] ? PhpstanRunResult::STATUS_PASS : $types->status;
        $status = ($rank[$typesStatus] ?? 2) > ($rank[$di->status] ?? 2) ? $typesStatus : $di->status;

        return new PhpstanRunResult(
            status: $status,
            diagnostics: [...$di->diagnostics, ...$extra],
            rawSignal: $di->rawSignal . '; ' . $types->rawSignal,
            exitCode: $status === PhpstanRunResult::STATUS_PASS ? 0 : max($di->exitCode, $types->exitCode, 1),
            accepted: [...$di->accepted, ...$types->accepted],
        );
    }

    /** @param array<string, mixed> $d */
    private static function site(array $d): string
    {
        return self::normalise((string) ($d['path'] ?? '')) . ':' . (int) ($d['line'] ?? 0) . ':' . ($d['identifier'] ?? '') . ':' . ($d['message'] ?? '');
    }

    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * How PHPStan names what a changed line declares: `::name()`, `::$name`,
     * `::NAME`. A whole new file declares nothing anyone else's line is
     * reported against yet.
     *
     * @param array<int, true>|null $changed
     * @return list<string>
     */
    private function declaredOn(string $relativePath, ?array $changed): array
    {
        if ($changed === null || $changed === []) {
            return [];
        }
        $source = @file($this->projectRoot . '/' . $relativePath, FILE_IGNORE_NEW_LINES);
        if ($source === false) {
            return [];
        }
        $names = [];
        foreach (array_keys($changed) as $line) {
            $text = $source[$line - 1] ?? '';
            if (preg_match('/\bfunction\s+&?\s*(\w+)\s*\(/', $text, $m) === 1 && !str_starts_with($m[1], '__')) {
                $names[] = '::' . $m[1] . '()';
            } elseif (preg_match('/^\s*(?:(?:public|protected|private|readonly|static|var)\s+)+[^;=(]*?\$(\w+)/', $text, $m) === 1) {
                $names[] = '::$' . $m[1];
            } elseif (preg_match('/\bconst\s+(?:\w+\s+)?(\w+)\s*=/', $text, $m) === 1) {
                $names[] = '::' . $m[1];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, true>|null the changed line numbers; null = the whole file
     */
    private function changedLines(string $relativePath): ?array
    {
        $abs = $this->projectRoot . '/' . $relativePath;
        $dir = dirname($abs);
        // safe.directory: ai:verify runs as whichever user the container
        // gives it, and a repository owned by another one made every git call
        // fail — the file then counted whole, every old error with it.
        $git = ['git', '-c', 'safe.directory=*', '--literal-pathspecs', '-C', $dir];

        // Not a repository, or a file git does not track: every line is new.
        if ($this->processRunner->run([...$git, 'ls-files', '--error-unmatch', '--', basename($abs)], $dir)['exit'] !== 0) {
            return null;
        }
        $diff = $this->processRunner->run([...$git, 'diff', '-U0', '--no-color', '--no-ext-diff', '--text', 'HEAD', '--', basename($abs)], $dir);
        if ($diff['exit'] !== 0) {
            return null;
        }

        $lines = [];
        preg_match_all('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/m', $diff['output'], $hunks, PREG_SET_ORDER);
        foreach ($hunks as $hunk) {
            $start = (int) $hunk[1];
            // No count is one line; +N,0 is a deletion, no line of this file.
            $count = isset($hunk[2]) ? (int) $hunk[2] : 1;
            for ($line = $start; $line < $start + $count; $line++) {
                $lines[$line] = true;
            }
        }

        return $lines;
    }
}
