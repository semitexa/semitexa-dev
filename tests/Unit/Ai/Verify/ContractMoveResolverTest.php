<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ContractMoveExpansion;
use Semitexa\Dev\Application\Service\Ai\Verify\ContractMoveResolver;
use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;

final class ContractMoveResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-contract-move-resolver-' . uniqid();
        mkdir($this->root . '/vendor/composer', 0755, true);
        mkdir($this->root . '/bin', 0755, true);

        // Synthetic project layout used by the fixtures:
        //   packages/semitexa-foo/src/Domain/Contract/RemovedContract.php  (deleted)
        //   packages/semitexa-bar/src/Application/Consumer.php             (still references it)
        mkdir($this->root . '/packages/semitexa-foo/src/Domain/Contract', 0755, true);
        mkdir($this->root . '/packages/semitexa-bar/src/Application', 0755, true);
        // Touch consumer file so realpath() can canonicalise it during resolution.
        file_put_contents(
            $this->root . '/packages/semitexa-bar/src/Application/Consumer.php',
            "<?php\nnamespace Semitexa\\Bar\\Application;\nfinal class Consumer {}\n",
        );
        // Touch a stand-in bin/semitexa so the resolver doesn't bail on missing CLI.
        file_put_contents($this->root . '/bin/semitexa', "#!/usr/bin/env bash\nexit 0\n");
        chmod($this->root . '/bin/semitexa', 0755);

        $this->writePsr4([
            'Semitexa\\Foo\\' => [$this->root . '/packages/semitexa-foo/src'],
            'Semitexa\\Bar\\' => [$this->root . '/packages/semitexa-bar/src'],
        ]);
        $this->writeClassmap([
            'Semitexa\\Bar\\Application\\Consumer'
                => $this->root . '/packages/semitexa-bar/src/Application/Consumer.php',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function test_deleted_contract_expands_to_known_consumers(): void
    {
        $runner = $this->processRunnerReturning(json_encode([
            [
                'source'   => 'class:Semitexa\\Bar\\Application\\Consumer',
                'target'   => 'class:Semitexa\\Foo\\Domain\\Contract\\RemovedContract',
                'type'     => 'imports',
                'metadata' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/RemovedContract.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_DELETED,
            ),
        ]);

        $this->assertCount(1, $expansions);
        $this->assertSame(
            'Semitexa\\Foo\\Domain\\Contract\\RemovedContract',
            $expansions[0]->contractFqcn,
        );
        $this->assertSame(
            ['packages/semitexa-bar/src/Application/Consumer.php'],
            $expansions[0]->dependentFiles,
        );
        $this->assertStringContainsString('removed', $expansions[0]->reason());
        $this->assertStringContainsString('1 dependent file', $expansions[0]->reason());
    }

    public function test_renamed_contract_expands_to_known_consumers(): void
    {
        $runner = $this->processRunnerReturning(json_encode([
            [
                'source' => 'class:Semitexa\\Bar\\Application\\Consumer',
                'target' => 'class:Semitexa\\Foo\\Domain\\Contract\\RemovedContract',
                'type'   => 'imports',
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/RemovedContract.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_RENAMED,
            ),
        ]);

        $this->assertCount(1, $expansions);
        $this->assertStringContainsString('renamed', $expansions[0]->reason());
    }

    public function test_modified_contract_does_not_expand(): void
    {
        $runner = $this->processRunnerReturning(json_encode([
            ['source' => 'class:Semitexa\\Bar\\Application\\Consumer', 'target' => 'class:X', 'type' => 'imports'],
        ], JSON_THROW_ON_ERROR));

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/RemovedContract.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_MODIFIED,
            ),
        ]);

        $this->assertSame([], $expansions);
    }

    public function test_deleted_file_with_no_known_consumers_yields_no_expansion(): void
    {
        // Graph reports empty edges → no orphans to flag.
        $runner = $this->processRunnerReturning('[]');

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/RemovedContract.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_DELETED,
            ),
        ]);

        $this->assertSame([], $expansions);
    }

    public function test_unrelated_change_set_yields_no_expansion(): void
    {
        // Test never invokes the CLI — runner would throw if called.
        $runner = $this->processRunnerThatFails('CLI must not be called for a contract-modify-only change set');

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Application/Service/Foo.php',
                ChangedFile::KIND_SERVICE,
                ChangedFile::STATUS_MODIFIED,
            ),
            new ChangedFile(
                'packages/semitexa-bar/src/Application/Consumer.php',
                ChangedFile::KIND_SERVICE,
                ChangedFile::STATUS_ADDED,
            ),
        ]);

        $this->assertSame([], $expansions);
    }

    public function test_non_php_deletion_is_ignored(): void
    {
        $runner = $this->processRunnerThatFails('CLI must not be called for a non-PHP deletion');

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/legacy.json',
                ChangedFile::KIND_NON_PHP,
                ChangedFile::STATUS_DELETED,
            ),
        ]);

        $this->assertSame([], $expansions);
    }

    public function test_deleted_file_outside_psr4_yields_no_expansion(): void
    {
        $runner = $this->processRunnerThatFails('CLI must not be called when FQCN cannot be derived');

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'random/scratch/UnmappedClass.php',
                ChangedFile::KIND_SERVICE,
                ChangedFile::STATUS_DELETED,
            ),
        ]);

        $this->assertSame([], $expansions);
    }

    public function test_self_edge_is_dropped(): void
    {
        // An edge that maps the removed contract to itself (a node's own
        // declaration is sometimes recorded as an edge in the graph) is
        // not a dependent file — the contract itself is gone.
        $runner = $this->processRunnerReturning(json_encode([
            [
                'source' => 'class:Semitexa\\Foo\\Domain\\Contract\\RemovedContract',
                'target' => 'class:Semitexa\\Foo\\Domain\\Contract\\RemovedContract',
                'type'   => 'declares',
            ],
        ], JSON_THROW_ON_ERROR));

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/RemovedContract.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_DELETED,
            ),
        ]);

        $this->assertSame([], $expansions);
    }

    public function test_cli_failure_is_silently_swallowed(): void
    {
        $runner = new class implements ProcessRunner {
            public function run(array $command, string $cwd): array
            {
                return ['exit' => 1, 'output' => 'graph store unavailable'];
            }
        };

        $resolver = new ContractMoveResolver($this->root, $runner);
        $expansions = $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/RemovedContract.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_DELETED,
            ),
        ]);

        $this->assertSame([], $expansions);
    }

    public function test_duplicate_fqcn_in_change_set_queries_graph_once(): void
    {
        $runner = new class implements ProcessRunner {
            public int $calls = 0;
            public function run(array $command, string $cwd): array
            {
                $this->calls++;
                return ['exit' => 0, 'output' => '[]'];
            }
        };
        $resolver = new ContractMoveResolver($this->root, $runner);
        $resolver->resolve([
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/RemovedContract.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_DELETED,
            ),
            new ChangedFile(
                'packages/semitexa-foo/src/Domain/Contract/RemovedContract.php',
                ChangedFile::KIND_CONTRACT,
                ChangedFile::STATUS_DELETED,
            ),
        ]);
        $this->assertSame(1, $runner->calls, 'dedup must collapse duplicate FQCNs to one graph query');
    }

    private function processRunnerReturning(string $jsonOutput): ProcessRunner
    {
        return new class($jsonOutput) implements ProcessRunner {
            public function __construct(private readonly string $payload) {}
            public function run(array $command, string $cwd): array
            {
                return ['exit' => 0, 'output' => $this->payload];
            }
        };
    }

    private function processRunnerThatFails(string $reason): ProcessRunner
    {
        return new class($reason) implements ProcessRunner {
            public function __construct(private readonly string $reason) {}
            public function run(array $command, string $cwd): array
            {
                throw new \LogicException($this->reason);
            }
        };
    }

    /**
     * @param array<string, list<string>> $map
     */
    private function writePsr4(array $map): void
    {
        $entries = [];
        foreach ($map as $prefix => $dirs) {
            $dirsRendered = implode(", ", array_map(static fn(string $d) => var_export($d, true), $dirs));
            $entries[] = sprintf('    %s => array(%s)', var_export($prefix, true), $dirsRendered);
        }
        $body = "<?php\nreturn array(\n" . implode(",\n", $entries) . ",\n);\n";
        file_put_contents($this->root . '/vendor/composer/autoload_psr4.php', $body);
    }

    /**
     * @param array<string, string> $map
     */
    private function writeClassmap(array $map): void
    {
        $entries = [];
        foreach ($map as $fqcn => $file) {
            $entries[] = sprintf('    %s => %s', var_export($fqcn, true), var_export($file, true));
        }
        $body = "<?php\nreturn array(\n" . implode(",\n", $entries) . ",\n);\n";
        file_put_contents($this->root . '/vendor/composer/autoload_classmap.php', $body);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
