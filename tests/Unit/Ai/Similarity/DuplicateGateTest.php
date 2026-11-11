<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Similarity;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateDetector;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateGate;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateQuery;
use Semitexa\Dev\Application\Service\Ai\Similarity\IndexedArtifact;
use Semitexa\Dev\Application\Service\Ai\Similarity\SimilarityIndex;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class DuplicateGateTest extends TestCase
{
    public function test_returns_exit_code_and_json_envelope_on_block(): void
    {
        $gate = new DuplicateGate();
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'handler',
                module: 'Foo',
                className: 'DupeHandler',
                fqcn: 'X\\DupeHandler',
                relativePath: 'src/modules/Foo/src/Application/Handler/PayloadHandler/DupeHandler.php',
                extras: [],
            ),
        ]));
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $exit = $gate->run(
            new DuplicateQuery(
                kind: 'handler',
                module: 'Foo',
                className: 'DupeHandler',
                fqcn: 'X\\DupeHandler',
                relativePath: 'src/modules/Foo/src/Application/Handler/PayloadHandler/DupeHandler.php',
            ),
            $detector,
            $io,
            $output,
            override: false,
            jsonOutput: true,
            llmHintsOutput: false,
        );

        $this->assertSame(1, $exit);
        $decoded = json_decode(trim($output->fetch()), true);
        $this->assertIsArray($decoded);
        $this->assertSame(DuplicateGate::REFUSAL_ARTIFACT, $decoded['artifact']);
        $this->assertSame('refused', $decoded['status']);
        $this->assertSame('duplicate_detected', $decoded['reason']);
        $this->assertSame('--override-duplicate', $decoded['override_flag']);
        $this->assertCount(1, $decoded['findings']);
        $this->assertSame('handler.same_class_in_module', $decoded['findings'][0]['rule']);
    }

    public function test_override_bypasses_block(): void
    {
        $gate = new DuplicateGate();
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'handler',
                module: 'Foo',
                className: 'DupeHandler',
                fqcn: 'X\\DupeHandler',
                relativePath: 'x.php',
                extras: [],
            ),
        ]));
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $exit = $gate->run(
            new DuplicateQuery(
                kind: 'handler',
                module: 'Foo',
                className: 'DupeHandler',
                fqcn: 'X\\DupeHandler',
                relativePath: 'x.php',
            ),
            $detector,
            $io,
            $output,
            override: true,
            jsonOutput: false,
            llmHintsOutput: false,
        );

        $this->assertNull($exit);
    }

    public function test_warn_only_does_not_block(): void
    {
        $gate = new DuplicateGate();
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'payload',
                module: 'Foo',
                className: 'GetUserPayload',
                fqcn: 'X\\GetUserPayload',
                relativePath: 'x.php',
                extras: [],
            ),
        ]));
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $exit = $gate->run(
            new DuplicateQuery(
                kind: 'payload',
                module: 'Foo',
                className: 'GetUsrPayload',
                fqcn: 'X\\GetUsrPayload',
                relativePath: 'y.php',
            ),
            $detector,
            $io,
            $output,
            override: false,
            jsonOutput: false,
            llmHintsOutput: false,
        );

        $this->assertNull($exit);
        $this->assertStringContainsString('GetUserPayload', $output->fetch());
    }

    public function test_clean_query_is_silent(): void
    {
        $gate = new DuplicateGate();
        $detector = new DuplicateDetector(new SimilarityIndex([]));
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $exit = $gate->run(
            new DuplicateQuery(
                kind: 'handler',
                module: 'Foo',
                className: 'BrandNewHandler',
                fqcn: 'X\\BrandNewHandler',
                relativePath: 'x.php',
            ),
            $detector,
            $io,
            $output,
            override: false,
            jsonOutput: true,
            llmHintsOutput: false,
        );

        $this->assertNull($exit);
        $this->assertSame('', $output->fetch());
    }

    /**
     * A route already bound is not waved through by --override-duplicate
     * (tk-guard-make-payload-override-orphan): the second payload left the
     * first one's handler unrouted, and the next boot failed.
     */
    public function test_override_does_not_bypass_a_route_already_bound(): void
    {
        $detector = new DuplicateDetector(new SimilarityIndex([
            new IndexedArtifact(
                kind: 'payload',
                module: 'Hello',
                className: 'HelloPayload',
                fqcn: 'App\\Modules\\Hello\\Application\\Payload\\Request\\HelloPayload',
                relativePath: 'src/modules/Hello/src/Application/Payload/Request/HelloPayload.php',
                extras: ['route_path' => '/', 'route_method' => 'GET'],
            ),
        ]));
        $query = new DuplicateQuery(
            kind: 'payload',
            module: 'Hello',
            className: 'LandingPayload',
            fqcn: 'App\\Modules\\Hello\\Application\\Payload\\Request\\LandingPayload',
            relativePath: 'src/modules/Hello/src/Application/Payload/Request/LandingPayload.php',
            extras: ['route_path' => '/', 'route_method' => 'GET'],
        );

        $output = new BufferedOutput();
        $exit = (new DuplicateGate())->run($query, $detector, new SymfonyStyle(new ArrayInput([]), $output), $output, override: true, jsonOutput: false, llmHintsOutput: false);
        $text = $output->fetch();

        $this->assertSame(1, $exit, 'refused even with --override-duplicate');
        $this->assertStringContainsString('src/modules/Hello/src/Application/Payload/Request/HelloPayload.php', $text, 'names the owner to change or delete');
        $this->assertStringContainsString('delete it together with its handler first', $text);
        $this->assertStringNotContainsString('Pass --override-duplicate to proceed anyway', $text);

        $json = new BufferedOutput();
        (new DuplicateGate())->run($query, $detector, new SymfonyStyle(new ArrayInput([]), $json), $json, override: true, jsonOutput: true, llmHintsOutput: false);
        $decoded = json_decode(trim($json->fetch()), true);
        $this->assertIsArray($decoded);
        $this->assertSame('route_already_bound', $decoded['reason']);
        $this->assertNull($decoded['override_flag'], 'no flag offered that would not work');
        $this->assertStringContainsString('HelloPayload would lose it', $decoded['how_to_override']);
    }
}
