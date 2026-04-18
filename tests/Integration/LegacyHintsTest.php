<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Capability\CapabilityRegistry;
use Semitexa\Dev\Console\Command\MakeCommand;
use Symfony\Component\Console\Application;

/**
 * Phase 5 step 1 is strictly additive — legacy commands keep working, but
 * agent-facing "next" hints must already point at the new surface. This test
 * locks in the hint updates so a later refactor can't quietly regress them.
 *
 * If a new legacy hint shows up, fail loudly here rather than during an
 * agent session.
 */
class LegacyHintsTest extends TestCase
{
    public function test_make_recipe_hint_points_at_ai_ask_not_ai_capabilities(): void
    {
        $app = new Application();
        $app->add(new MakeCommand());
        $option = $app->find('make')->getDefinition()->getOption('recipe');
        $description = $option->getDescription();

        $this->assertStringContainsString('ai:ask capabilities', $description);
        $this->assertStringNotContainsString('ai:capabilities', $this->stripNewHint($description));
    }

    public function test_capability_registry_project_follow_up_points_at_ai_ask_module(): void
    {
        foreach (CapabilityRegistry::all() as $cap) {
            if ($cap->name === 'describe:project') {
                $this->assertSame(['ai:ask module'], $cap->follow_up);
                return;
            }
        }
        $this->fail('describe:project capability missing from registry');
    }

    public function test_no_legacy_follow_up_in_capability_registry(): void
    {
        $legacy = ['describe:project', 'describe:module', 'describe:route', 'describe:event', 'ai:capabilities', 'ai:mine-conventions', 'logs:app'];
        foreach (CapabilityRegistry::all() as $cap) {
            foreach ($cap->follow_up as $hint) {
                // Bare make:* entries are internal scaffolding primitives, still a valid follow-up.
                if (str_starts_with($hint, 'make:')) {
                    continue;
                }
                $this->assertNotContains($hint, $legacy, "capability '{$cap->name}' still points agents at deprecated '{$hint}'");
            }
        }
    }

    /**
     * "ai:ask capabilities" contains "ai:capabilities" as a substring — strip
     * the new-surface token first so the regression assertion only flags an
     * unqualified legacy mention.
     */
    private function stripNewHint(string $value): string
    {
        return str_replace('ai:ask capabilities', '', $value);
    }
}
