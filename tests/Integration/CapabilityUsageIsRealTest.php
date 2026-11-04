<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Api\ApiCatalog;
use Semitexa\Dev\Application\Service\Capability\FrameworkCapabilityCatalog;

/**
 * A capability's usage is copied by agents, so a name in it that no longer exists
 * teaches something false with authority. Example classes are the reader's own and
 * are not checked; the framework's attributes, parents and static calls are.
 */
final class CapabilityUsageIsRealTest extends TestCase
{
    #[Test]
    public function the_names_in_every_usage_exist(): void
    {
        $discovery = new ClassDiscovery();
        $discovery->initialize();
        $api = ApiCatalog::forFramework($discovery->getClassMap(), ProjectRoot::get());

        $wrong = [];
        $checked = 0;
        foreach ((new FrameworkCapabilityCatalog($discovery))->all() as $capability) {
            $usage = $capability['usage'];
            if ($usage === '') {
                continue;
            }
            $checked++;

            preg_match_all('/#\[([A-Z]\w+)/', $usage, $attributes);
            preg_match_all('/\b(?:implements|extends)\s+([A-Z]\w+)/', $usage, $parents);
            foreach ([...$attributes[1], ...$parents[1]] as $name) {
                if ($api->resolve($name) === []) {
                    $wrong[] = "{$capability['id']}: no class {$name}";
                }
            }

            preg_match_all('/\b([A-Z]\w+)::([a-z]\w*)\(/', $usage, $calls, PREG_SET_ORDER);
            foreach ($calls as [, $class, $method]) {
                $resolved = $api->resolve($class);
                if ($resolved === []) {
                    $wrong[] = "{$capability['id']}: no class {$class}";
                }
                foreach ($resolved as $candidate) {
                    /** @var class-string $candidate */
                    if (!(new ReflectionClass($candidate))->hasMethod($method)) {
                        $wrong[] = "{$capability['id']}: no method {$class}::{$method}";
                    }
                }
            }
        }

        $this->assertGreaterThan(5, $checked, 'the usages this test exists for are not being found');
        $this->assertSame([], $wrong);
    }
}
