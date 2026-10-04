<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\RuleStats;

use Semitexa\Dev\Application\Service\Ai\Verify\ProjectGuardTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation;
use Semitexa\Dev\Application\Service\Ai\Verify\Tenancy\LiveTenancyViolation;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;

/**
 * The rules ai:verify can fail on today, each with the family whose run gives
 * it a chance. Read from the rules themselves, never from a list kept here: a
 * rule removed from its source leaves the catalog on its own. A source that
 * cannot be read throws: an incomplete catalog would report live rules as
 * renamed or removed.
 */
final class RuleCatalog
{
    public function __construct(private readonly string $projectRoot) {}

    /** @return array<string, string> rule id => family */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->phpstanIdentifiers() as $identifier) {
            $rules[$identifier] = RuleFires::PHPSTAN;
        }
        foreach ((new \ReflectionClass(ModuleStructureViolation::class))->getConstants() as $name => $code) {
            if (str_starts_with($name, 'CODE_') && is_string($code)) {
                $rules[$code] = RuleFires::MODULE_STRUCTURE;
            }
        }
        foreach (array_keys(LiveTenancyViolation::RATIONALES) as $code) {
            $rules[$code] = RuleFires::LIVE_TENANCY;
        }
        foreach ($this->commandGates() as $gate) {
            $rules[$gate] = $gate;
        }
        foreach (glob($this->projectRoot . '/' . ProjectGuardTargets::RATCHET_SUITE . '/*Test.php') ?: [] as $file) {
            $rules[basename($file, '.php')] = RuleFires::RATCHETS;
        }
        ksort($rules);

        return $rules;
    }

    /**
     * The identifiers the registered Semitexa rules emit, plus the one the
     * runner itself names (a broken FQCN, remapped from PHPStan's own ids).
     *
     * @return list<string>
     */
    private function phpstanIdentifiers(): array
    {
        $neon = $this->projectRoot . '/vendor/semitexa/core/config/phpstan-rules.neon';
        $source = is_file($neon) ? file_get_contents($neon) : false;
        if ($source === false) {
            throw new \RuntimeException("cannot read the PHPStan rule list {$neon}");
        }
        preg_match_all('/^\s*-\s*(Semitexa\\\\[A-Za-z0-9\\\\]+Rule)\s*$/m', $source, $classes);
        if ($classes[1] === []) {
            throw new \RuntimeException("no rules found in {$neon}");
        }
        $identifiers = ['semitexa.brokenFqcn'];
        foreach ($classes[1] as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException("{$class} is registered in {$neon} but cannot be loaded");
            }
            $file = (new \ReflectionClass($class))->getFileName();
            preg_match_all("/->identifier\\('(semitexa\\.[A-Za-z0-9]+)'\\)/", is_string($file) ? (string) file_get_contents($file) : '', $found);
            array_push($identifiers, ...$found[1]);
        }

        return array_values(array_unique($identifiers));
    }

    /** @return list<string> lint and docs commands ai:verify plans, as their targets name them */
    private function commandGates(): array
    {
        $gates = [];
        $constants = (new \ReflectionClass(VerificationPlanner::class))->getConstants();
        $lists = is_array($constants['KIND_LINT_MAP'] ?? null) ? array_values($constants['KIND_LINT_MAP']) : [];
        $lists[] = $constants['ALL_LINTS'] ?? [];
        foreach ($lists as $commands) {
            foreach (is_array($commands) ? $commands : [] as $command) {
                if (is_string($command) && str_starts_with($command, 'lint:')) {
                    $gates[] = $command;
                }
            }
        }
        foreach ([VerificationPlanner::class, ProjectGuardTargets::class] as $class) {
            $source = (string) file_get_contents((string) (new \ReflectionClass($class))->getFileName());
            preg_match_all("/\\bid:\\s*'((?:lint|docs):[a-z0-9:-]+)'/", $source, $ids);
            array_push($gates, ...$ids[1]);
        }

        return array_values(array_unique($gates));
    }
}
