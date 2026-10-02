<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What review-prep refuses to publish in a PR title or description
 * (scripts/check-public-text.py reads the same file, on the host, before the
 * push). An agent's "proof of work" — a screenshot, an uploaded recording, a
 * log path — went public with the PR it proved (Glow Labs PixelLeak,
 * 2026-09-29). Each pattern is pinned both ways: a refusal that also fires on
 * an ordinary description would be bypassed by habit within a week.
 */
final class PrivateEvidencePatternsTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../resources/skills/review-prep/private-evidence-patterns.json';

    /** @return array<string, string> id => PCRE */
    private static function patterns(): array
    {
        $data = json_decode((string) file_get_contents(self::FILE), true, flags: JSON_THROW_ON_ERROR);
        $patterns = [];
        foreach ($data['patterns'] as $pattern) {
            $patterns[$pattern['id']] = '~' . $pattern['regex'] . '~';
        }

        return $patterns;
    }

    /** @return list<string> the ids that fire, the way the script reads: line by line */
    private static function findings(string $text): array
    {
        $fired = [];
        foreach (explode("\n", $text) as $line) {
            foreach (self::patterns() as $id => $regex) {
                if (preg_match($regex, $line) === 1) {
                    $fired[] = $id;
                }
            }
        }

        return array_values(array_unique($fired));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'a markdown screenshot' => ['embedded-image', '![after the fix](shot.png)'];
        yield 'an html image' => ['embedded-image', '<img width="600" src="x.png">'];
        yield 'an uploaded attachment link' => ['uploaded-attachment', 'Recording: https://github.com/user-attachments/assets/1f2e'];
        yield 'an old-style upload' => ['uploaded-attachment', 'https://user-images.githubusercontent.com/1/2.png'];
        yield 'a pasted data uri' => ['data-uri', 'src="data:image/png;base64,iVBORw0KGgo"'];
        yield 'a home directory' => ['local-path', 'Log: /home/taras/Documents/app/var/log/error.log'];
        yield 'a home directory in backticks' => ['local-path', 'see `/home/dev/x`'];
        yield 'a mac home' => ['local-path', 'at /Users/anna/work/app'];
        yield 'an agent scratchpad' => ['local-path', 'saved to /tmp/claude-1000/abc/shot.png'];
        yield 'a windows home' => ['local-path', 'C:\\Users\\anna\\shot.png'];
        yield 'a github token' => ['token', 'GH_TOKEN=ghp_' . str_repeat('a1', 18)];
        yield 'an anthropic key' => ['token', 'sk-ant-api03-' . str_repeat('x', 24)];
        yield 'an aws key' => ['token', 'AKIAIOSFODNN7EXAMPLE'];
        yield 'a private key' => ['token', '-----BEGIN OPENSSH PRIVATE KEY-----'];
        // Missed by the first version (self-review, 2026-10-02):
        yield 'an image tag in capitals' => ['embedded-image', '<IMG SRC="x.png">'];
        yield 'a video tag' => ['embedded-image', '<video src="run.webm" controls>'];
        yield 'a reference-style image' => ['embedded-image', '![shot][1]'];
        yield 'an svg data uri' => ['data-uri', 'url(data:image/svg+xml;utf8,<svg/>)'];
        yield 'a data uri with a charset' => ['data-uri', 'data:image/png;charset=utf-8;base64,iVBOR'];
        yield 'a path inside a code tag' => ['local-path', '<code>/home/taras/a</code>'];
        yield 'a file url' => ['local-path', 'file:///home/taras/x.png'];
        yield 'a path after a colon' => ['local-path', 'path:/home/taras/x'];
        yield 'a project key' => ['token', 'OPENAI_API_KEY=sk-proj-' . str_repeat('aB3_-', 6)];
        yield 'a token after a non-ascii letter' => ['token', 'éghp_' . str_repeat('a1', 18)];
    }

    #[Test]
    #[DataProvider('refused')]
    public function private_evidence_is_refused(string $id, string $text): void
    {
        self::assertContains($id, self::findings($text));
    }

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'a link to another pull request' => ['Follows [#42](https://github.com/semitexa/dev/pull/42).'];
        yield 'a relative path' => ['Changed `src/Application/Service/Foo.php` and `tests/Unit/FooTest.php`.'];
        yield 'the container path every install shares' => ['Runs at /var/www/html inside the app container.'];
        yield 'a path that merely ends in home' => ['Served from public/home/index.html.'];
        yield 'an exclamation before a link' => ['Fixed! [docs](https://semitexa.com/docs/)'];
        yield 'a commit sha' => ['Reverts 54c808f1a2b3c4d5e6f708192a3b4c5d6e7f8091.'];
        yield 'the attribution line' => ['🤖 Generated with [Claude Code](https://claude.com/claude-code)'];
        yield 'a word that starts like a key' => ['The task-sk-ant-handler and AKIA are names here.'];
        yield 'a path that ends in a home folder' => ['See src/home/index.php and docs/Users/guide.md.'];
        yield 'the word data with a colon' => ['Data: the importer reads data:rows from the feed.'];
        yield 'an exclamation and a reference link' => ['Done! [1] is the issue.'];
    }

    #[Test]
    #[DataProvider('allowed')]
    public function an_ordinary_description_passes(string $text): void
    {
        self::assertSame([], self::findings($text));
    }

    #[Test]
    public function every_pattern_compiles_and_has_both_cases(): void
    {
        $refusedIds = array_unique(array_map(static fn (array $case): string => $case[0], iterator_to_array(self::refused())));
        foreach (self::patterns() as $id => $regex) {
            // '~' is the delimiter here: in a pattern it broke every PCRE reader silently.
            self::assertStringNotContainsString('~', substr($regex, 1, -1), $id . ' contains the delimiter');
            self::assertNotFalse(@preg_match($regex, ''), $id . ' is not valid PCRE');
            self::assertContains($id, $refusedIds, $id . ' has no refuse case');
        }
    }
}
