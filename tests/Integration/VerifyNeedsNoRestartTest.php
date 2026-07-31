<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Dev\Application\Console\Command\AiVerifyCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Verification does not need a restarted server, and now says so.
 *
 * A consumer agent reported a 30–60s verify cycle as its single biggest time
 * sink, having wrapped every run in `server:restart` + `cache:clear`. Measured
 * here: 16s of restart around 4s of verification — four fifths of the cycle
 * spent on a step that changed nothing, because each check runs in a fresh CLI
 * process that rediscovers from disk.
 *
 * The habit survived because nothing in the output contradicted it. An unstated
 * assumption is not corrected by being wrong; it is corrected by something
 * saying otherwise at the moment it is being acted on.
 */
final class VerifyNeedsNoRestartTest extends TestCase
{
    /**
     * @param list<ChangedFile> $files
     * @return array<string, mixed>
     */
    private static function advice(array $files): array
    {
        $m = new ReflectionMethod(AiVerifyCommand::class, 'restartAdvice');
        $m->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $m->invoke(new AiVerifyCommand(), $files);

        return $result;
    }

    #[Test]
    public function verification_never_claims_to_need_a_restart(): void
    {
        // The load-bearing claim. If this ever flips to true, the 4x cycle cost
        // comes back and nobody will notice, because it will look like caution.
        foreach ([
            [new ChangedFile('src/x.php', ChangedFile::KIND_SERVICE)],
            [new ChangedFile('t.html.twig', ChangedFile::KIND_TEMPLATE)],
            [new ChangedFile('a.js', ChangedFile::KIND_CLIENT_SCRIPT)],
            [new ChangedFile('README.md', ChangedFile::KIND_NON_PHP)],
            [],
        ] as $files) {
            self::assertFalse(self::advice($files)['needed_for_verification']);
        }
    }

    #[Test]
    public function the_note_states_why_rather_than_only_what(): void
    {
        // "No restart needed" alone gets read as a default someone will
        // override under pressure. The reason is what makes it stick.
        $note = (string) self::advice([])['note'];

        self::assertStringContainsString('fresh process', $note);
    }

    #[Test]
    public function code_and_template_changes_still_get_the_browser_warning(): void
    {
        // The other half of being trustworthy: the restart is real before
        // exercising the running server, and dropping that would trade one
        // wrong belief for its opposite.
        foreach ([
            new ChangedFile('src/x.php', ChangedFile::KIND_SERVICE),
            new ChangedFile('page.html.twig', ChangedFile::KIND_TEMPLATE),
            new ChangedFile('module.js', ChangedFile::KIND_CLIENT_SCRIPT),
        ] as $file) {
            $advice = self::advice([$file]);

            self::assertArrayHasKey('before_browsing', $advice, $file->path);
            self::assertStringContainsString('server:restart', (string) $advice['before_browsing']);
        }
    }

    #[Test]
    public function a_documentation_change_gets_no_browser_warning(): void
    {
        // Nothing the running server holds, so nothing to restart for. Advising
        // it anyway is how the advice becomes noise and the habit returns.
        self::assertArrayNotHasKey('before_browsing', self::advice([
            new ChangedFile('docs/readme.md', ChangedFile::KIND_NON_PHP),
        ]));
    }

    #[Test]
    public function the_advice_reaches_the_default_output_and_not_only_the_json_envelope(): void
    {
        // It shipped in the `--json` envelope alone, and NDJSON is what an
        // ordinary run prints — so the sentence written to correct a habit
        // never appeared where the habit was being acted on. Advice the reader
        // does not see is the same as no advice, with the added cost of looking
        // handled.
        $method = new ReflectionMethod(AiVerifyCommand::class, 'emitNdjson');
        $method->setAccessible(true);

        $output = new BufferedOutput();
        $method->invoke(
            new AiVerifyCommand(),
            $output,
            new VerificationPlan(
                scope: VerificationPlan::SCOPE_MINIMAL,
                effectiveScope: VerificationPlan::SCOPE_MINIMAL,
                changedFiles: [new ChangedFile('packages/semitexa-dev/src/X.php', ChangedFile::KIND_PHP_OTHER)],
                targets: [],
                expansions: [],
            ),
            [],
            'pass',
        );

        $kinds = [];
        $restart = null;
        foreach (explode("\n", trim($output->fetch())) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            $kinds[] = $decoded['kind'] ?? null;
            if (($decoded['kind'] ?? null) === 'restart') {
                $restart = $decoded;
            }
        }

        self::assertContains('restart', $kinds, 'NDJSON carried no restart record');
        self::assertFalse($restart['needed_for_verification'] ?? null);
        // Ordered before the verdict: a reader who stops at the result never
        // reaches a line printed after it.
        self::assertLessThan(
            array_search('verdict', $kinds, true),
            array_search('restart', $kinds, true),
        );
    }
}
