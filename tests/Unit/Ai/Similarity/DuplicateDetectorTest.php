<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Similarity;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Similarity\DuplicateDetector;
use Semitexa\Dev\Ai\Similarity\DuplicateQuery;
use Semitexa\Dev\Ai\Similarity\IndexedArtifact;
use Semitexa\Dev\Ai\Similarity\SimilarityFinding;
use Semitexa\Dev\Ai\Similarity\SimilarityIndex;

class DuplicateDetectorTest extends TestCase
{
    public function test_handler_same_class_in_module_blocks(): void
    {
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'handler',
                module: 'Foo',
                className: 'GetThingHandler',
                fqcn: 'Semitexa\\Modules\\Foo\\Application\\Handler\\PayloadHandler\\GetThingHandler',
                relativePath: 'src/modules/Foo/Application/Handler/PayloadHandler/GetThingHandler.php',
                extras: [
                    'payload_fqcn' => 'Semitexa\\Modules\\Foo\\Application\\Payload\\Request\\GetThingPayload',
                    'resource_fqcn' => 'Semitexa\\Modules\\Foo\\Application\\Resource\\Response\\GetThingResponse',
                ],
            ),
        ]));

        $findings = $detector->check(new DuplicateQuery(
            kind: 'handler',
            module: 'Foo',
            className: 'GetThingHandler',
            fqcn: 'Semitexa\\Modules\\Foo\\Application\\Handler\\PayloadHandler\\GetThingHandler',
            relativePath: 'src/modules/Foo/Application/Handler/PayloadHandler/GetThingHandler.php',
            extras: [
                'payload_fqcn' => 'Semitexa\\Modules\\Foo\\Application\\Payload\\Request\\OtherPayload',
                'resource_fqcn' => 'Semitexa\\Modules\\Foo\\Application\\Resource\\Response\\OtherResponse',
            ],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(SimilarityFinding::SEVERITY_BLOCK, $findings[0]->severity);
        $this->assertSame('handler.same_class_in_module', $findings[0]->rule);
    }

    public function test_handler_same_payload_resource_pair_blocks(): void
    {
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'handler',
                module: 'Foo',
                className: 'FirstHandler',
                fqcn: 'X\\FirstHandler',
                relativePath: 'src/modules/Foo/Application/Handler/PayloadHandler/FirstHandler.php',
                extras: [
                    'payload_fqcn' => 'X\\GetThingPayload',
                    'resource_fqcn' => 'X\\GetThingResponse',
                ],
            ),
        ]));

        $findings = $detector->check(new DuplicateQuery(
            kind: 'handler',
            module: 'Foo',
            className: 'SecondHandler',
            fqcn: 'X\\SecondHandler',
            relativePath: 'src/modules/Foo/Application/Handler/PayloadHandler/SecondHandler.php',
            extras: [
                'payload_fqcn' => 'X\\GetThingPayload',
                'resource_fqcn' => 'X\\GetThingResponse',
            ],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(SimilarityFinding::SEVERITY_BLOCK, $findings[0]->severity);
        $this->assertSame('handler.duplicate_payload_resource_pair', $findings[0]->rule);
        $this->assertStringContainsString('GetThingPayload', $findings[0]->message);
    }

    public function test_payload_route_collision_blocks(): void
    {
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'payload',
                module: 'Bar',
                className: 'OldPayload',
                fqcn: 'X\\OldPayload',
                relativePath: 'src/modules/Bar/Application/Payload/Request/OldPayload.php',
                extras: ['route_path' => '/foo/{id}', 'route_method' => 'GET'],
            ),
        ]));

        $findings = $detector->check(new DuplicateQuery(
            kind: 'payload',
            module: 'Foo',
            className: 'NewPayload',
            fqcn: 'X\\NewPayload',
            relativePath: 'src/modules/Foo/Application/Payload/Request/NewPayload.php',
            extras: ['route_path' => '/foo/{id}', 'route_method' => 'GET'],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame('payload.route_already_bound', $findings[0]->rule);
        $this->assertSame(SimilarityFinding::SEVERITY_BLOCK, $findings[0]->severity);
        $this->assertStringContainsString('/foo/{id}', $findings[0]->message);
    }

    public function test_payload_close_name_warns(): void
    {
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'payload',
                module: 'Foo',
                className: 'GetUserPayload',
                fqcn: 'X\\GetUserPayload',
                relativePath: 'src/modules/Foo/Application/Payload/Request/GetUserPayload.php',
                extras: [],
            ),
        ]));

        $findings = $detector->check(new DuplicateQuery(
            kind: 'payload',
            module: 'Foo',
            className: 'GetUsrPayload',
            fqcn: 'X\\GetUsrPayload',
            relativePath: 'src/modules/Foo/Application/Payload/Request/GetUsrPayload.php',
            extras: [],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(SimilarityFinding::SEVERITY_WARN, $findings[0]->severity);
        $this->assertSame('payload.close_name_in_module', $findings[0]->rule);
    }

    public function test_listener_duplicate_event_warns(): void
    {
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'listener',
                module: 'Foo',
                className: 'ExistingListener',
                fqcn: 'X\\ExistingListener',
                relativePath: 'src/modules/Foo/Application/Handler/DomainListener/ExistingListener.php',
                extras: ['event_fqcn' => 'Y\\UserRegistered'],
            ),
        ]));

        $findings = $detector->check(new DuplicateQuery(
            kind: 'listener',
            module: 'Foo',
            className: 'NewListener',
            fqcn: 'X\\NewListener',
            relativePath: 'src/modules/Foo/Application/Handler/DomainListener/NewListener.php',
            extras: ['event_fqcn' => 'Y\\UserRegistered'],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(SimilarityFinding::SEVERITY_WARN, $findings[0]->severity);
        $this->assertSame('listener.duplicate_event_subscription', $findings[0]->rule);
    }

    public function test_no_findings_when_clean(): void
    {
        $detector = new DuplicateDetector(new SimilarityIndex([]));
        $findings = $detector->check(new DuplicateQuery(
            kind: 'handler',
            module: 'Foo',
            className: 'BrandNewHandler',
            fqcn: 'X\\BrandNewHandler',
            relativePath: 'src/modules/Foo/Application/Handler/PayloadHandler/BrandNewHandler.php',
        ));

        $this->assertSame([], $findings);
    }
}
