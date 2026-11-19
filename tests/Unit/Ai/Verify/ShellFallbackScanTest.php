<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Shell\ShellFallbackScan;

/**
 * lint:shell-fallbacks (tk-verify-catches-review-no-classes, class 1a): in POSIX sh a failed
 * redirection on a special builtin exits the shell, so a fallback written around it never runs.
 * The scripts here are strings, never files: the lint reads every script of the tree, and a
 * failing fixture on disk would be a finding of its own.
 */
final class ShellFallbackScanTest extends TestCase
{
    private const REVIEWED = <<<'SH'
        #!/bin/sh
        d="$1"
        if mkdir -p "$d" && : > "$d/x.log" 2>/dev/null; then
            echo "writable"
        else
            echo "fallback"
        fi

        SH;

    /** @var list<string> */
    private array $trash = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->trash) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    #[Test]
    public function the_reviewed_case_is_flagged(): void
    {
        self::assertSame([[3, ':']], self::flagged(self::REVIEWED));
    }

    #[Test]
    public function a_subshell_around_the_builtin_passes(): void
    {
        self::assertSame([], self::flagged(<<<'SH'
            #!/bin/sh
            if mkdir -p "$d" && ( : > "$d/x.log" ) 2>/dev/null; then echo ok; else echo fallback; fi
            ( exec 3> "$f" ) || echo fallback
            x=$(: > "$f" && echo yes) || echo fallback
            if `: > "$f"`; then echo ok; fi

            SH));
    }

    #[Test]
    public function a_plain_statement_passes(): void
    {
        self::assertSame([], self::flagged(<<<'SH'
            #!/bin/sh
            : > "$f"
            exec 3> "$log"
            if [ -d "$d" ]; then
                : > "$d/x.log"
            else
                exec > /tmp/out
            fi
            while read -r line; do : > "$line"; done < list
            truncate() { : > "$1"; }

            SH));
    }

    #[Test]
    public function an_operand_something_depends_on_is_flagged(): void
    {
        self::assertSame([[2, ':'], [3, ':'], [4, ':'], [5, ':'], [6, 'exec']], self::flagged(<<<'SH'
            #!/bin/sh
            : > x || echo fallback
            if [ -f x ] || : > x; then echo there; fi
            [ -f x ] || : > x || echo fallback
            { [ -f x ] || : > x; } && echo there
            [ -f x ] || ! exec 3> x

            SH));
    }

    /** Nothing reads the status of the last operand of a plain statement: no handling is skipped. */
    #[Test]
    public function the_last_operand_of_a_plain_statement_passes(): void
    {
        $script = <<<'SH'
            #!/bin/sh
            [ -f x ] || : > x
            [ -d "$d" ] && [ -w "$d" ] && exec 3> "$d/log"
            if [ -n "$1" ]; then
                [ -f "$1" ] || : > "$1"
            fi
            touch_it() { [ -f "$1" ] || : > "$1"; }

            SH;

        self::assertSame([], self::flagged($script));
        // The same list where its status is read is the finding: the test above is not passing on a blind parser.
        self::assertSame([[2, ':']], self::flagged("#!/bin/sh
while [ -f x ] || : > x; do sleep 1; done
"));
    }

    #[Test]
    public function exec_as_a_condition_is_flagged(): void
    {
        self::assertSame([[2, 'exec'], [5, 'exec'], [8, 'exec']], self::flagged(<<<'SH'
            #!/bin/sh
            if exec 3>"$f"; then echo open; else echo fallback; fi
            while
                read -r x
                exec 4< "$x"
            do echo "$x"; done
            until
                exec >> "$log"
            do sleep 1; done

            SH));
    }

    #[Test]
    public function every_condition_position_is_read(): void
    {
        self::assertSame(
            [[2, 'set'], [3, 'export'], [4, '.'], [5, 'eval'], [6, 'readonly'], [7, 'unset'], [10, ':']],
            self::flagged(<<<'SH'
                #!/bin/sh
                if true; then echo a; elif set -e > "$f"; then echo b; fi
                ! export A=1 > "$f"
                . ./lib.sh < "$in" && echo sourced
                { eval "$cmd" >| "$f"; } || echo fallback
                A=1 readonly B > "$f" || echo fallback
                if unset A <> "$f"; then :; fi
                case "$1" in
                    a|b) echo plain ;;
                    (c) : > "$f" || true ;;
                    *) : > "$f" ;;
                esac

                SH),
        );
    }

    #[Test]
    public function what_cannot_exit_the_shell_passes(): void
    {
        self::assertSame([], self::flagged(<<<'SH'
            #!/bin/sh
            if : 2>/dev/null; then echo ok; fi
            exec 2>&1 || echo "no dup"
            exec 3>&- && echo closed
            if command : > "$f"; then echo ok; fi
            if echo x > "$f"; then echo ok; fi
            : > "$f" | cat || echo piped
            : > "$f" && echo background &
            if set -- a b; then echo ok; fi
            for f in a b; do : > "$f"; done || echo plain-body
            echo "if : > f; then" '[ -f x ] || : > f'
            # if : > f; then echo comment; fi

            SH));
    }

    #[Test]
    public function a_heredoc_holding_the_pattern_passes(): void
    {
        self::assertSame([[9, ':']], self::flagged(<<<'SH'
            #!/bin/sh
            cat <<EOF > "$out"
            if mkdir -p "$d" && : > "$d/x.log" 2>/dev/null; then echo ok; fi
            [ -f x ] || : > f
            EOF
            cat <<-'TABBED' || echo fallback
            	if exec 3> f; then echo ok; fi
            	TABBED
            : > after-the-heredocs || echo fallback

            SH), 'the heredoc bodies are data; the line after them is read again, at its own number');
    }

    #[Test]
    public function a_bash_script_is_skipped(): void
    {
        $body = "\nif : > \"\$f\"; then echo ok; else echo fallback; fi\n";

        self::assertTrue(ShellFallbackScan::isPosixSh('#!/bin/sh' . $body));
        self::assertTrue(ShellFallbackScan::isPosixSh('#!/bin/sh -e' . $body));
        self::assertTrue(ShellFallbackScan::isPosixSh('#!/usr/bin/env sh' . $body));
        self::assertFalse(ShellFallbackScan::isPosixSh('#!/bin/bash' . $body));
        self::assertFalse(ShellFallbackScan::isPosixSh('#!/usr/bin/env bash' . $body));
        self::assertFalse(ShellFallbackScan::isPosixSh('#!/usr/bin/env php' . $body));
        self::assertFalse(ShellFallbackScan::isPosixSh(ltrim($body)));

        $root = $this->project([
            'bin/tool' => '#!/bin/sh' . $body,
            'bin/tool-in-bash' => '#!/bin/bash' . $body,
            'scripts/deep/again.sh' => '#!/usr/bin/env bash' . $body,
        ]);

        self::assertSame(['bin/tool'], array_keys(ShellFallbackScan::problems($root)));
    }

    #[Test]
    public function the_marker_accepts_a_finding_and_a_short_reason_does_not(): void
    {
        $result = ShellFallbackScan::scan(<<<'SH'
            #!/bin/sh
            # verify:accept-shell-fallback the directory was created two lines above, so this cannot fail
            : > a || echo fallback
            : > b || echo fallback # verify:accept-shell-fallback: exiting here is what the caller wants
            # verify:accept-shell-fallback ok
            { : > c; } || echo fallback
            echo "# verify:accept-shell-fallback a string is data, never a comment that accepts"
            : > d || echo fallback

            SH);

        self::assertSame([6, 8], array_column($result['findings'], 'line'));
        self::assertSame([true, false], array_column($result['findings'], 'shortReason'));
        self::assertSame(
            [3 => 'the directory was created two lines above, so this cannot fail', 4 => 'exiting here is what the caller wants'],
            array_column($result['accepted'], 'reason', 'line'),
        );
    }

    #[Test]
    public function a_project_scan_names_the_file_the_line_and_the_fix(): void
    {
        $root = $this->project([
            'bin/semitexa' => self::REVIEWED,
            'packages/acme/bin/run' => "#!/bin/sh\n: > \"\$f\"\n",
            'packages/acme/resources/setup.sh' => "#!/bin/sh\nexec 3> x || echo fallback\n",
            'packages/acme/vendor/other/bin/run' => self::REVIEWED,
            'packages/acme/resources/broken.sh' => "#!/bin/sh\necho \"never closed\n",
        ]);

        $problems = ShellFallbackScan::problems($root);

        self::assertSame(['bin/semitexa', 'packages/acme/resources/broken.sh', 'packages/acme/resources/setup.sh'], array_keys($problems));
        self::assertCount(1, $problems['bin/semitexa']);
        self::assertStringStartsWith('line 3: `:` with a file redirection is in the condition of if;', $problems['bin/semitexa'][0]);
        self::assertStringContainsString('( : > "$file" ) 2>/dev/null', $problems['bin/semitexa'][0]);
        self::assertStringStartsWith('line 2: `exec` with a file redirection', $problems['packages/acme/resources/setup.sh'][0]);
        self::assertStringStartsWith('could not be parsed', $problems['packages/acme/resources/broken.sh'][0]);

        self::assertSame(['packages/acme/resources/setup.sh'], array_keys(ShellFallbackScan::problems($root, ['packages/acme/resources/setup.sh', 'packages/acme/bin/run'])));
    }

    /**
     * The rule is about what the shell does, so the shell says it: the flagged script never
     * reaches its fallback, and the fix the lint prints does.
     */
    #[Test]
    public function the_shell_this_runs_on_exits_where_the_lint_says_so(): void
    {
        $tail = "\"\$d/x.log\" 2>/dev/null; then echo writable; else echo fallback; fi\necho after\n";
        $root = $this->project([
            'flagged.sh' => "#!/bin/sh\nd=/nonexistent-dir-for-this-test\nif true && : > " . $tail,
            'fixed.sh' => "#!/bin/sh\nd=/nonexistent-dir-for-this-test\nif true && ( : > \"\$d/x.log\" ) 2>/dev/null; then echo writable; else echo fallback; fi\necho after\n",
        ]);

        self::assertSame([[3, ':']], self::flagged((string) file_get_contents($root . '/flagged.sh')));
        self::assertSame([], self::flagged((string) file_get_contents($root . '/fixed.sh')));

        [$flaggedOut, $flaggedExit] = self::sh($root . '/flagged.sh');
        [$fixedOut, $fixedExit] = self::sh($root . '/fixed.sh');

        self::assertSame('', $flaggedOut, 'the shell left before the fallback and before the next statement');
        self::assertNotSame(0, $flaggedExit);
        self::assertSame("fallback\nafter\n", $fixedOut);
        self::assertSame(0, $fixedExit);
    }

    /** @return list<array{int, string}> line and builtin of each finding */
    private static function flagged(string $script): array
    {
        return array_map(
            static fn (array $finding): array => [$finding['line'], $finding['builtin']],
            ShellFallbackScan::scan($script)['findings'],
        );
    }

    /** @return array{string, int} stdout and the exit code */
    private static function sh(string $script): array
    {
        $process = proc_open(['/bin/sh', $script], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [$out, proc_close($process)];
    }

    /** @param array<string, string> $files relative path => content */
    private function project(array $files): string
    {
        $root = sys_get_temp_dir() . '/semitexa-shell-fallbacks-' . uniqid();
        mkdir($root, 0755, true);
        $this->trash[] = $root;
        foreach ($files as $relative => $content) {
            $dir = $root;
            foreach (explode('/', dirname($relative)) as $segment) {
                if ($segment === '.') {
                    continue;
                }
                $dir .= '/' . $segment;
                if (!is_dir($dir)) {
                    mkdir($dir, 0755);
                    $this->trash[] = $dir;
                }
            }
            file_put_contents($root . '/' . $relative, $content);
            $this->trash[] = $root . '/' . $relative;
        }

        return $root;
    }
}
