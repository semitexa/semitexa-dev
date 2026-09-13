<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationOutcomeRenderer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A `make:*` command that writes nothing and exits non-zero has to say why.
 *
 * Plain text used to report conflicts and nothing else, so a refused path or
 * unparseable output produced a silent failure — the reason sat in the JSON
 * envelope, which a person running the command by hand never sees.
 */
final class GenerationOutcomeRendererTest extends TestCase
{
    private function render(GenerationResult $result): string
    {
        $output = new BufferedOutput();
        GenerationOutcomeRenderer::renderProblems(new SymfonyStyle(new ArrayInput([]), $output), $result);

        return (string) preg_replace('/\s+/', ' ', $output->fetch());
    }

    /** @param array<string, mixed> $overrides */
    private function outcome(string $status, array $overrides = []): GenerationResult
    {
        return new GenerationResult(
            command: 'make:thing',
            status: $status,
            conflicts: $overrides['conflicts'] ?? [],
            verify: $overrides['verify'] ?? null,
            errors: $overrides['errors'] ?? null,
        );
    }

    #[Test]
    public function a_clean_result_says_nothing_at_all(): void
    {
        self::assertSame('', trim($this->render($this->outcome('created'))));
    }

    #[Test]
    public function a_conflict_names_the_file_and_the_way_out(): void
    {
        $text = $this->render($this->outcome('conflict', ['conflicts' => ['src/Thing.php']]));

        self::assertStringContainsString('src/Thing.php', $text);
        self::assertStringContainsString('--force', $text, 'naming the conflict without the escape is half an answer');
    }

    /**
     * The case that printed nothing: the writer refused the batch before
     * touching the disk, and the command exited 1 with an empty terminal.
     */
    #[Test]
    public function a_refused_write_says_which_path_and_why(): void
    {
        $text = $this->render($this->outcome('rejected', [
            'errors' => [['path' => '../escaped.php', 'reason' => 'traversal', 'detail' => 'leaves the project root']],
        ]));

        self::assertStringContainsString('../escaped.php', $text);
        self::assertStringContainsString('traversal', $text);
        self::assertStringContainsString('leaves the project root', $text);
    }

    #[Test]
    public function an_empty_path_is_still_shown_as_something(): void
    {
        $text = $this->render($this->outcome('rejected', [
            'errors' => [['path' => '', 'reason' => 'empty', 'detail' => 'a planned file had no path']],
        ]));

        self::assertStringContainsString('empty path', $text, 'a blank line reads as a rendering bug');
    }

    #[Test]
    public function code_that_does_not_parse_is_reported_against_the_file(): void
    {
        $text = $this->render($this->outcome('created', [
            'verify' => [
                'status' => 'fail',
                'checked' => 2,
                'errors' => [['file' => 'src/Thing.php', 'message' => 'syntax error, unexpected token ";"']],
            ],
        ]));

        self::assertStringContainsString('src/Thing.php', $text);
        self::assertStringContainsString('unexpected token', $text);
    }

    #[Test]
    public function a_passing_verify_block_is_not_announced(): void
    {
        $text = $this->render($this->outcome('created', [
            'verify' => ['status' => 'pass', 'checked' => 2, 'errors' => []],
        ]));

        self::assertSame('', trim($text));
    }

    #[Test]
    public function every_reason_is_reported_not_just_the_first(): void
    {
        $text = $this->render($this->outcome('partial', [
            'conflicts' => ['src/Existing.php'],
            'errors' => [['path' => 'src/Bad.php', 'reason' => 'control', 'detail' => 'control character in path']],
            'verify' => [
                'status' => 'fail',
                'checked' => 1,
                'errors' => [['file' => 'src/Written.php', 'message' => 'syntax error']],
            ],
        ]));

        self::assertStringContainsString('src/Existing.php', $text);
        self::assertStringContainsString('src/Bad.php', $text);
        self::assertStringContainsString('src/Written.php', $text);
    }
}
