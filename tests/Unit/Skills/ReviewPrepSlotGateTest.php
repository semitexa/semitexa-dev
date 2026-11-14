<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * create-review-pr.sh holds make-it's limits instead of a sentence asking for them
 * (tk-prepush-refuses-red-verify): on 2026-11-12 a fourth PR was opened by hand
 * while three were in review. A new PR waits; the open one can still be updated.
 */
final class ReviewPrepSlotGateTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/review-prep-gate-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0o755, true);
        $repo = $this->dir . '/repo';
        $git = 'git -C ' . escapeshellarg($repo) . ' -c user.email=t@example.invalid -c user.name=t';
        exec('git init -q -b develop ' . escapeshellarg($repo) . " && {$git} commit -q --allow-empty -m init"
            . " && {$git} remote add origin git@github.com:semitexa/semitexa-orm.git"
            . " && {$git} update-ref refs/remotes/origin/master HEAD", $out, $code);
        self::assertSame(0, $code, 'a throwaway repository on develop');
        file_put_contents($this->dir . '/body.md', "## Summary\n\nA change.\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function a_new_pr_is_refused_when_every_review_slot_is_taken(): void
    {
        [$status, $out] = $this->open(['semitexa/semitexa-core 231', 'semitexa/semitexa-docs 142', 'semitexa/semitexa-dev 244']);

        self::assertSame(1, $status, implode("\n", $out));
        self::assertStringContainsString('Refusing to open a PR for semitexa/semitexa-orm: no: 3 of 3 review slots are taken', implode("\n", $out));
        self::assertStringNotContainsString('PUSHED', implode("\n", $out), 'refused before anything reached GitHub');
    }

    #[Test]
    public function a_review_answer_may_go_over_the_limit_when_it_says_so(): void
    {
        [, $out] = $this->open(['semitexa/semitexa-core 231', 'semitexa/semitexa-docs 142', 'semitexa/semitexa-dev 244'], ['--allow-over-limit']);

        self::assertStringNotContainsString('Refusing to open a PR', implode("\n", $out));
        self::assertStringContainsString('PUSHED', implode("\n", $out), 'it went on to push');
    }

    /**
     * @param list<string> $open "<owner/repo> <number>" open PRs the listing returns
     * @param list<string> $extra
     * @return array{int, list<string>}
     */
    private function open(array $open, array $extra = []): array
    {
        $answer = 'printf %s ' . escapeshellarg(implode("\n", $open) . "\n");
        // repo view fails (the slug comes from the remote), pr list finds no PR for the branch,
        // wip.sh's API listing returns the open PRs (tk-wip-count-misses-just-opened-prs); anything
        // else, a push included, is reported and stopped.
        file_put_contents($this->dir . '/bin/gh', "#!/bin/bash\ncase \" \$* \" in\n"
            . "  ' repo view '*) exit 1 ;;\n"
            . "  ' pr list '*) exit 0 ;;\n"
            . "  ' api graphql --paginate -f owner=semitexa '*) {$answer} ;;\n"
            . "  *) echo \"unexpected gh \$*\" >&2; exit 1 ;;\nesac\n");
        file_put_contents($this->dir . '/bin/git', "#!/bin/bash\nif [ \"\$3\" = push ]; then echo PUSHED; exit 1; fi\nexec /usr/bin/git \"\$@\"\n");
        // A mock that is not executable would let the real gh or git run: stop here (review, dev#245).
        self::assertTrue(chmod($this->dir . '/bin/gh', 0o755) && chmod($this->dir . '/bin/git', 0o755), 'the gh and git stand-ins are executable');

        $script = dirname(__DIR__, 3) . '/resources/skills/review-prep/scripts/create-review-pr.sh';
        exec(sprintf(
            // --allow-private-evidence: the evidence check (python) is another gate, not this one.
            'env -i HOME=%s PATH=%s:/usr/bin:/bin MAKE_IT_WIP=3 bash %s %s --title %s --body-file %s --allow-private-evidence %s 2>&1',
            escapeshellarg($this->dir),
            escapeshellarg($this->dir . '/bin'),
            escapeshellarg($script),
            escapeshellarg($this->dir . '/repo'),
            escapeshellarg('fix: a change'),
            escapeshellarg($this->dir . '/body.md'),
            implode(' ', array_map('escapeshellarg', $extra)),
        ), $out, $status);

        return [$status, $out];
    }
}
