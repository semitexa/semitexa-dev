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
                relativePath: 'src/modules/Foo/Application/Handler/PayloadHandler/DupeHandler.php',
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
                relativePath: 'src/modules/Foo/Application/Handler/PayloadHandler/DupeHandler.php',
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
}
