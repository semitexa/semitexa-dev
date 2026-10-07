<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * JSON a command prints must reach stdout byte for byte.
 *
 * `$output->writeln()` runs Symfony's formatter: it reads `<tag>` as a style
 * and `\<` as an escaped `<`. JSON escapes a backslash as `\\`, so a value
 * holding `\<M>` (a namespace pattern in a task note) came out as `\<M>` — an
 * invalid escape — and `ai:work list --json` stopped parsing for every agent
 * reading it (2026-10-07). 195 call sites printed JSON that way, among them
 * ai:verify and ai:orient. Passing OutputInterface::OUTPUT_RAW skips the
 * formatter; this test keeps every `write`/`writeln(json_encode(…))` doing so.
 */
final class JsonOutputIsRawTest extends TestCase
{
    /** Why this guard exists; ai:verify prints it when the guard fails (PhpunitFailureHeadline). */
    public const RATIONALE = 'Why: Symfony\'s formatter rewrites `\\<` inside JSON into an invalid escape, so machine output stops parsing. Learned 2026-10-07: ai:work list --json broke on one task note; 195 call sites printed JSON through the formatter.';

    #[Test]
    public function every_command_prints_its_json_raw(): void
    {
        $formatted = [];
        foreach ($this->sourceFiles() as $path => $source) {
            foreach (self::formattedJsonWrites($source) as $line) {
                $formatted[] = $path . ':' . $line;
            }
        }

        self::assertSame([], $formatted, "These print json_encode() through the console formatter, which corrupts backslashes before '<'. Pass OutputInterface::OUTPUT_RAW (write: false, OutputInterface::OUTPUT_RAW).");
    }

    #[Test]
    public function the_scan_finds_what_it_is_meant_to_find(): void
    {
        $bad = <<<'PHP'
            <?php
            $output->writeln(json_encode(['a' => 1]));
            $output->writeln((string) json_encode([
                'b' => 2,
            ]));
            $output->write(json_encode([]), false);
            PHP;
        $good = <<<'PHP'
            <?php
            $output->writeln(json_encode(['a' => 1]), OutputInterface::OUTPUT_RAW);
            $output->write((string) json_encode([]), false, OutputInterface::OUTPUT_RAW);
            $output->writeln('plain text');
            PHP;

        self::assertSame([2, 3, 6], self::formattedJsonWrites($bad));
        self::assertSame([], self::formattedJsonWrites($good));
    }

    /**
     * Lines of `->write(…)` / `->writeln(…)` calls whose first argument is
     * json_encode(…) (cast or not) and that do not pass OUTPUT_RAW.
     *
     * @return list<int>
     */
    private static function formattedJsonWrites(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], ['write', 'writeln'], true)) {
                continue;
            }
            $before = self::skipWhitespace($tokens, $i - 1, -1);
            if ($before < 0 || !is_array($tokens[$before]) || !in_array($tokens[$before][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $open = self::skipWhitespace($tokens, $i + 1, 1);
            if (($tokens[$open] ?? null) !== '(') {
                continue;
            }
            $first = self::skipWhitespace($tokens, $open + 1, 1);
            if (is_array($tokens[$first] ?? null) && $tokens[$first][0] === T_STRING_CAST) {
                $first = self::skipWhitespace($tokens, $first + 1, 1);
            }
            if (!is_array($tokens[$first] ?? null) || $tokens[$first][1] !== 'json_encode') {
                continue;
            }
            $depth = 0;
            $call = '';
            for ($m = $open; $m < $count; $m++) {
                $text = is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
                $call .= $text;
                if (in_array($text, ['(', '[', '{', '${'], true)) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true) && --$depth === 0) {
                    break;
                }
            }
            if (!str_contains($call, 'OUTPUT_RAW')) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /** @param list<mixed> $tokens */
    private static function skipWhitespace(array $tokens, int $at, int $step): int
    {
        while (isset($tokens[$at]) && is_array($tokens[$at]) && $tokens[$at][0] === T_WHITESPACE) {
            $at += $step;
        }

        return $at;
    }

    /** @return array<string, string> path relative to packages/ => source */
    private function sourceFiles(): array
    {
        $packages = dirname(__DIR__, 4);
        $files = [];
        foreach (glob($packages . '/semitexa-*/src', GLOB_ONLYDIR) ?: [] as $src) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $source = (string) file_get_contents($file->getPathname());
                    if (str_contains($source, 'json_encode')) {
                        $files[substr($file->getPathname(), strlen($packages) + 1)] = $source;
                    }
                }
            }
        }
        ksort($files);

        return $files;
    }
}
