<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Support;

use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphSchema;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;

/**
 * A two-module project written to a temporary directory and scanned by the
 * real project-graph engine into in-memory SQLite, for the UI flow tests:
 * the Observatory's `ui` view, the `lint:ui-flow` warning and `ai:ask
 * ui-flow` all read this graph.
 *
 * Shop: the orders page links to a path no route answers (/nowhere) and to
 * the blog; one POST nobody reaches (/orders/archive) and one accepted with
 * a reason (/orders/import). Blog: a page with a dead link of its own (/gone)
 * and its own unreached POST, so a test can tell one module's findings from
 * the other's.
 *
 * Campaign\ sources: nothing autoloads them, the graph reads the files.
 */
final class UiFlowShop
{
    public const ORDERS = 'class:Campaign\\Shop\\OrdersPayload';
    public const BLOG = 'class:Campaign\\Blog\\BlogPayload';
    public const ORDERS_TEMPLATE = 'src/modules/Shop/Application/View/templates/pages/orders.html.twig';
    public const BLOG_TEMPLATE = 'src/modules/Blog/Application/View/templates/pages/blog.html.twig';
    public const SHOP_SOURCE = 'src/modules/Shop/Application/Shop.php';
    public const BLOG_SOURCE = 'src/modules/Blog/Application/Blog.php';

    private const FILES = [
        self::SHOP_SOURCE => <<<'PHP'
            <?php
            namespace Campaign\Shop;

            use Semitexa\Core\Attribute\AsPublicPayload;
            use Semitexa\Core\Attribute\AsResource;
            use Semitexa\ProjectGraph\Attribute\UiEntryPoint;
            use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

            #[AsPublicPayload(path: '/orders', methods: ['GET'], responseWith: OrdersResource::class)]
            final class OrdersPayload {}

            #[AsResource(template: '@Shop/pages/orders.html.twig')]
            final class OrdersResource extends HtmlResponse {}

            #[AsPublicPayload(path: '/orders/archive', methods: ['POST'], responseWith: OrdersResource::class)]
            final class ArchiveOrdersPayload {}

            #[AsPublicPayload(path: '/orders/import', methods: ['POST'], responseWith: OrdersResource::class)]
            #[UiEntryPoint(reason: 'Posted by the import script on the orders page.')]
            final class ImportOrdersPayload {}
            PHP,
        self::ORDERS_TEMPLATE => <<<'TWIG'
            <a href="/nowhere">Planted: no route</a>
            <a href="/blog">The blog</a>
            <a href="{{ row.href }}">Computed</a>
            TWIG,
        self::BLOG_SOURCE => <<<'PHP'
            <?php
            namespace Campaign\Blog;

            use Semitexa\Core\Attribute\AsPublicPayload;
            use Semitexa\Core\Attribute\AsResource;
            use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

            #[AsPublicPayload(path: '/blog', methods: ['GET'], responseWith: BlogResource::class)]
            final class BlogPayload {}

            #[AsResource(template: '@Blog/pages/blog.html.twig')]
            final class BlogResource extends HtmlResponse {}

            #[AsPublicPayload(path: '/blog/draft', methods: ['POST'], responseWith: BlogResource::class)]
            final class DraftPayload {}
            PHP,
        self::BLOG_TEMPLATE => <<<'TWIG'
            <a href="/gone">Planted in the other module</a>
            <a href="/orders">Back to the shop</a>
            TWIG,
    ];

    private readonly IncrementalEngine $engine;

    private function __construct(
        public readonly string $root,
        public readonly GraphStorage $storage,
    ) {
        $this->engine = new IncrementalEngine(
            new FileScanner(new IgnorePatternLoader()),
            new PhpParserAdapter(),
            new ExtractorPipeline(ExtractorPipeline::default()),
            new GraphBuilder($storage),
            $storage,
        );
    }

    public static function built(): self
    {
        $root = sys_get_temp_dir() . '/semitexa-dev-ui-flow-' . bin2hex(random_bytes(6));
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        GraphSchema::create($orm->getAdapter());
        $shop = new self($root, new GraphStorage(
            $orm->getAdapter(),
            $orm->getTransactionManager(),
            $orm->getMapperRegistry(),
            $orm->getResourceModelHydrator(),
            $orm->getResourceModelMetadataRegistry(),
            $orm->getResourceModelRelationLoader(),
            $orm->getAggregateWriteEngine(),
        ));
        foreach (self::FILES as $path => $contents) {
            $shop->write($path, $contents);
        }
        $shop->engine->fullBuild($root);

        return $shop;
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        clearstatcache();
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }
}
