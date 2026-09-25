<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Toolbar;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Dev\Application\Service\Explorer\RouteCatalog;
use Semitexa\Dev\Application\Service\Trace\ObservatoryContext;
use Semitexa\Dev\Application\Service\Trace\ObservatoryMode;
use Semitexa\Dev\Application\Service\Trace\TraceContext;
use Semitexa\Ssr\Domain\Contract\PageDocumentContributorInterface;

/**
 * The dev toolbar at the bottom of every page, Symfony-style.
 *
 * Only a placeholder and a script tag go into the page. The placeholder
 * carries what is known while the page is still rendering — which route
 * answered, the Observatory process id — as data attributes; the script
 * asks `/__toolbar/process/{id}` for the rest (status, time, queries) once the
 * response has gone and the journal holds its end line. So the page pays a
 * few hundred bytes, and nothing inline for a CSP to refuse.
 *
 * Dev only: APP_ENV=dev, not the production monitor mode. Off with
 * SEMITEXA_DEV_TOOLBAR=0.
 */
#[AsService]
#[SatisfiesServiceContract(of: PageDocumentContributorInterface::class)]
final class DevToolbarContributor implements PageDocumentContributorInterface
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $discovery;

    public function bodyEnd(): string
    {
        if (!self::enabled()) {
            return '';
        }
        $request = CurrentRequestStore::get();
        if ($request === null) {
            return '';
        }

        $path = $request->getPath();
        if (str_starts_with($path, '/__')) {
            return '';
        }
        $method = strtoupper($request->getMethod());

        $attributes = [
            'data-process' => ObservatoryContext::currentId() ?? '',
            'data-method' => $method,
            'data-path' => $path,
            'data-traced' => TraceContext::current() !== null ? '1' : '0',
        ];

        $raw = $this->discovery->findRoute($path, $method);
        $entry = is_array($raw) ? (RouteCatalog::fromRoutes([$raw])[0] ?? null) : null;
        if ($entry !== null) {
            $attributes += [
                'data-route-id' => $entry->id,
                'data-route' => $entry->path,
                'data-name' => $entry->name ?? '',
                'data-module' => $entry->module,
                'data-payload' => $entry->payload,
                'data-handler' => $entry->handler ?? '',
                'data-kind' => $entry->kind->value,
                'data-access' => $entry->access,
            ];
        }

        $html = '<div id="semitexa-devbar" hidden';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }

        return $html . '></div><script src="/__toolbar/asset/toolbar.js" defer></script>';
    }

    public static function enabled(): bool
    {
        return ObservatoryMode::resolve() === ObservatoryMode::DEV
            && Environment::getEnvValue('SEMITEXA_DEV_TOOLBAR') !== '0';
    }
}
