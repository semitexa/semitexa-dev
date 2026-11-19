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
        self::assertStringContainsString('Run it in a subshell: ( : > "$d/x.log" ) 2>/dev/null', $problems['bin/semitexa'][0]);
        self::assertStringStartsWith('line 2: `exec` with a file redirection', $problems['packages/acme/resources/setup.sh'][0]);
        self::assertStringStartsWith('could not be parsed', $problems['packages/acme/resources/broken.sh'][0]);

        self::assertSame(['packages/acme/resources/setup.sh'], array_keys(ShellFallbackScan::problems($root, ['packages/acme/resources/setup.sh', 'packages/acme/bin/run'])));
    }

    /** Review, dev#282: the advice once named `( : > "$file" )` for every builtin, which empties a file the script only reads or appends to. */
    #[Test]
    public function the_advice_keeps_the_redirection_the_script_wrote(): void
    {
        $root = $this->project([
            'bin/read' => "#!/bin/sh\nif exec 3< \"\$config\"; then echo open; else echo fallback; fi\n",
            'bin/append' => "#!/bin/sh\n: >> \"\$log\" || echo fallback\n",
            'bin/both' => "#!/bin/sh\nexec < \"\$in\" >> \"\$out\" 2>/dev/null || echo fallback\n",
        ]);

        $problems = ShellFallbackScan::problems($root);

        self::assertSame(['bin/append', 'bin/both', 'bin/read'], array_keys($problems));
        // The whole advice, to its end: nothing in it writes to a file the script only reads.
        self::assertStringEndsWith('so the fallback never runs. Try the same redirection in a subshell first, and run the builtin only when that worked: ( : < "$config" ) 2>/dev/null && exec …', $problems['bin/read'][0]);
        self::assertStringContainsString('Run it in a subshell: ( : >> "$log" ) 2>/dev/null', $problems['bin/append'][0]);
        self::assertStringContainsString('( : < "$in" >> "$out" ) 2>/dev/null && exec', $problems['bin/both'][0]);
    }

    /** Review, dev#282: a longer name that only starts with the marker accepted the finding. */
    #[Test]
    public function only_the_exact_marker_name_accepts(): void
    {
        $result = ShellFallbackScan::scan(<<<'SH'
            #!/bin/sh
            # verify:accept-shell-fallbacks are not allowed anywhere in this script
            : > "$log" || echo fallback
            # verify:accept-shell-fallback-not this is another word, whatever follows it
            : > "$log" || echo fallback

            SH);

        self::assertSame([3, 5], array_column($result['findings'], 'line'));
        self::assertSame([false, false], array_column($result['findings'], 'shortReason'));
        self::assertSame([], $result['accepted']);
    }

    /** Review, dev#282: quote characters that belong to the file name were trimmed away. */
    #[Test]
    public function only_dev_null_itself_is_exempt(): void
    {
        self::assertSame([[2, ':'], [3, ':']], self::flagged(<<<'SH'
            #!/bin/sh
            : > '/dev/null"' || echo fallback
            : > "'/dev/null" || echo fallback
            : > "/dev/null" || echo fine
            : > '/dev/null' || echo fine
            : > /dev/nul\l || echo fine

            SH));
    }

    /** Review, dev#282: valid sh the parser refused or misread. */
    #[Test]
    public function reserved_words_as_plain_words_are_read_as_words(): void
    {
        self::assertSame([[3, ':']], self::flagged(<<<'SH'
            #!/bin/sh
            for x in do done; do echo "$x"; done
            : > "$log" || echo fallback

            SH));
        self::assertSame([[4, ':']], self::flagged(<<<'SH'
            #!/bin/sh
            x=$(case case in (case) echo ok;; esac)
            y=$(case a in a) case b in b) echo in ;; esac ;; (c|esac) echo c ;; esac)
            : > "$log" || echo fallback

            SH));
    }

    /** Review, dev#282: a backslash-newline inside a word is no part of the word. */
    #[Test]
    public function a_line_continuation_does_not_hide_the_builtin(): void
    {
        self::assertSame([[2, ':'], [4, 'exec']], self::flagged("#!/bin/sh\n:\\\n > \"\$log\" || echo fallback\nex\\\nec 3> \"\$log\" || echo fallback\n"));
    }

    /** Review, dev#282: the delimiter is the word after quote removal, so a quoted quote stays in it. */
    #[Test]
    public function a_heredoc_ends_at_its_delimiter_after_quote_removal(): void
    {
        self::assertSame([[6, ':'], [10, ':']], self::flagged(<<<'SH'
            #!/bin/sh
            cat <<'E"OF'
            EOF
            : > inside || echo this is data
            E"OF
            : > "$log" || echo fallback
            cat <<E\OF"'"
            : > inside || echo this is data too
            EOF'
            : > "$log" || echo fallback

            SH));
    }

    /** Review round 2, dev#282: inside double quotes a backslash-newline joins two lines too, and is no part of the delimiter. */
    #[Test]
    public function a_continued_double_quoted_delimiter_is_joined(): void
    {
        self::assertSame([[6, ':']], self::flagged("#!/bin/sh\ncat <<\"E\\\nOF\"\n: > inside || echo this is data\nEOF\n: > \"\$log\" || echo fallback\ncat <<'E\\\nOF'\n: > inside || echo this is data too\nE\\\nOF\n: > \"\$log\" || echo fallback\n"), 'in single quotes the pair stays in the delimiter, which no single line can then equal: that heredoc runs to the end and line 12 is data');
    }

    /** Review, dev#282: a redirection with nothing after it took the newline for its target. */
    #[Test]
    public function a_compound_command_redirected_to_nothing_is_not_parsed(): void
    {
        self::assertSame([], self::flagged("#!/bin/sh\n{ :; } > \"\$log\"\n"));

        $root = $this->project(['bin/cut' => "#!/bin/sh\n{ :; } >\n"]);
        $problems = ShellFallbackScan::problems($root);
        self::assertSame(['bin/cut'], array_keys($problems));
        self::assertStringStartsWith('could not be parsed (line 2: `>` with no target)', $problems['bin/cut'][0]);
    }

    /** A script the lint cannot open is a problem, never a script it did not see (review, dev#282). */
    #[Test]
    public function a_script_link_with_no_target_is_reported(): void
    {
        $root = $this->project(['scripts/real.sh' => "#!/bin/sh\necho fine\n"]);
        symlink($root . '/scripts/gone.sh', $root . '/scripts/deploy.sh');
        $this->trash[] = $root . '/scripts/deploy.sh';

        self::assertSame(['scripts/deploy.sh' => ['cannot be read: the lint did not check it']], ShellFallbackScan::problems($root));
    }

    /** Review, dev#282: a root-level script is in no directory the lint walks, so the changed paths are given to it. */
    #[Test]
    public function given_files_are_read_alone_or_beside_the_project(): void
    {
        $bad = "#!/bin/sh\n: > \"\$log\" || echo fallback\n";
        $root = $this->project(['deploy.sh' => $bad, 'bin/tool' => $bad]);

        self::assertSame(['bin/tool'], array_keys(ShellFallbackScan::problems($root)));
        self::assertSame(['deploy.sh'], array_keys(ShellFallbackScan::problems($root, ['deploy.sh'])));
        self::assertSame(['bin/tool', 'deploy.sh'], array_keys(ShellFallbackScan::problems($root, ['deploy.sh', 'bin/tool'], true)));
        // An empty path names no script: it fails, and never turns into a scan of the project.
        self::assertSame(['(empty --file)'], array_keys(ShellFallbackScan::problems($root, [''])));
    }

    /**
     * The rule is about what the shell does, so the shell says it: the flagged script reaches
     * the redirection and never its fallback, and the fix the lint prints does.
     */
    #[Test]
    public function the_shell_this_runs_on_exits_where_the_lint_says_so(): void
    {
        // The target is a directory this test made: opening it for writing fails for any user, on any filesystem.
        $root = $this->project(['target/keep' => '']);
        $head = "#!/bin/sh\nd=" . escapeshellarg($root . '/target') . "\necho before\n";
        $tail = "; then echo writable; else echo fallback; fi\necho after\n";
        file_put_contents($root . '/flagged.sh', $head . 'if true && : > "$d" 2>/dev/null' . $tail);
        file_put_contents($root . '/fixed.sh', $head . 'if true && ( : > "$d" ) 2>/dev/null' . $tail);
        array_push($this->trash, $root . '/flagged.sh', $root . '/fixed.sh');

        self::assertSame([[4, ':']], self::flagged((string) file_get_contents($root . '/flagged.sh')));
        self::assertSame([], self::flagged((string) file_get_contents($root . '/fixed.sh')));

        [$flaggedOut, $flaggedExit] = self::sh($root . '/flagged.sh');
        [$fixedOut, $fixedExit] = self::sh($root . '/fixed.sh');

        self::assertSame("before\n", $flaggedOut, 'the shell reached the redirection and left before the fallback and the next statement');
        self::assertNotSame(0, $flaggedExit);
        self::assertSame("before\nfallback\nafter\n", $fixedOut);
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

    /** Review, dev#282: every POSIX special builtin counts, not only the common ones. */
    #[Test]
    public function every_posix_special_builtin_is_one(): void
    {
        self::assertSame([[2, 'trap'], [3, 'shift'], [4, 'times'], [5, 'return'], [6, 'exit']], self::flagged(<<<'SH'
            #!/bin/sh
            trap > "$log" || echo fallback
            shift 2> "$log" || echo fallback
            times > "$log" || echo fallback
            if return 2> "$log"; then :; fi
            exit 3> "$log" || echo fallback
            SH));
    }
}
