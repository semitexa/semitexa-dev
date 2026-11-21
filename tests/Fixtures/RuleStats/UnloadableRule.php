<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\RuleStats;

/**
 * A rule as a project without PHPStan sees it: the file is on the autoload
 * path, the interface it implements exists nowhere, so declaring the class is
 * a fatal error. RuleCatalog must read this file and never load it.
 */
final class UnloadableRule implements \Semitexa\Dev\Tests\Fixtures\RuleStats\Absent\RuleInterfaceOfAnUninstalledPhpstan
{
    public function report(): self
    {
        return $this->identifier('semitexa.ruleStatsFixtureUnloadable');
    }

    private function identifier(string $identifier): self
    {
        return $identifier === '' ? new self() : $this;
    }
}
