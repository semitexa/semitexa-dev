<?php

declare(strict_types=1);

// Run inside a downstream project's container by release-downstream-discovery.sh:
// the same discovery a worker boot and orm:sync run, so a route the candidate
// refuses fails here instead of in the host's auto-deploy.

require '/var/www/html/vendor/autoload.php';

use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\ModuleRegistry;

chdir('/var/www/html');

try {
    $discovery = new AttributeDiscovery(new ClassDiscovery(), new ModuleRegistry(), new RouteRegistry());
    $discovery->initialize();
    echo 'routes discovered: ' . count($discovery->getRoutes()) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
