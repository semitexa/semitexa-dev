<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pointer that makes the capability catalog reachable at all.
 *
 * A catalog nobody is told to run changes nothing, and this is the one link in
 * the chain that lives in prose rather than in code — so it is the one that
 * silently rots. The docs are mirrored root → `semitexa-ultimate` and from there
 * into consumer projects, so a pointer dropped from either copy quietly returns
 * every new project to the state this work exists to fix.
 *
 * It must stay a POINTER. A list of capabilities copied into the docs freezes at
 * scaffold time: the project would then be taught a shrinking subset of what its
 * installed packages can do, and would never learn about anything added later.
 * That is worse than silence, because it reads as authoritative.
 */
final class ShippedDocsAnnounceMechanismsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';
    private const SCAFFOLD = self::ROOT . '/packages/semitexa-ultimate';

    /** @return list<array{string, string}> */
    public static function shippedDocs(): array
    {
        return [
            'root AGENTS.md' => [self::ROOT . '/AGENTS.md', 'AGENTS.md'],
            'root AI_ENTRY.md' => [self::ROOT . '/AI_ENTRY.md', 'AI_ENTRY.md'],
            'shipped AGENTS.md' => [self::SCAFFOLD . '/AGENTS.md', 'AGENTS.md'],
            'shipped AI_ENTRY.md' => [self::SCAFFOLD . '/AI_ENTRY.md', 'AI_ENTRY.md'],
        ];
    }

    /**
     * @param string $path
     * @param string $label
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('shippedDocs')]
    public function it_tells_the_agent_where_to_find_framework_mechanisms(string $path, string $label): void
    {
        self::assertFileExists($path, $label . ' is missing');
        $body = (string) file_get_contents($path);

        self::assertStringContainsString(
            'ai:ask mechanisms',
            $body,
            $label . ' no longer points at the mechanism catalog; a consumer project scaffolded from it '
            . 'would never be told the framework has deferred regions, components or live transport',
        );
    }

    #[Test]
    public function the_two_catalogs_are_not_confused_for_each_other(): void
    {
        // `capabilities` lists CLI commands; `mechanisms` lists what the
        // framework can do. Both must remain reachable and distinct, or the
        // agent asks one question and gets the other answer.
        $body = (string) file_get_contents(self::ROOT . '/AGENTS.md');

        self::assertStringContainsString('ai:ask capabilities', $body);
        self::assertStringContainsString('ai:ask mechanisms', $body);
    }

    #[Test]
    public function the_docs_point_rather_than_enumerate(): void
    {
        // A capability id hard-coded into the docs is the failure mode: it is a
        // copy that cannot follow the installed packages. Naming the AREAS
        // (ssr, ui) is fine — those are stable filters, not contents.
        foreach ([self::ROOT . '/AGENTS.md', self::ROOT . '/AI_ENTRY.md'] as $path) {
            $body = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression(
                '/\b(ssr|ui)\.[a-z][a-z0-9-]*\b/',
                $body,
                basename($path) . ' enumerates capability ids; it must point at the catalog instead, '
                . 'or it freezes at scaffold time and goes stale as the framework grows',
            );
        }
    }
}
