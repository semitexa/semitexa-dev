<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Semitexa\Core\Console\Application;

class ContractsListCommandTest extends TestCase
{
    public function test_contracts_list_exits_successfully(): void
    {
        $app = new Application();
        $command = $app->find('contracts:list');

        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $code = $command->run($input, $output);

        $this->assertSame(0, $code);
    }

    public function test_contracts_list_json_output_is_valid(): void
    {
        $app = new Application();
        $command = $app->find('contracts:list');

        $input = new ArrayInput(['--json' => true]);
        $output = new BufferedOutput();
        $code = $command->run($input, $output);
        $this->assertSame(0, $code);

        $out = $output->fetch();
        $decoded = json_decode($out, true);
        $this->assertNotNull($decoded, 'Output should be valid JSON');
        $this->assertArrayHasKey('contracts', $decoded);
        $this->assertIsArray($decoded['contracts']);
        foreach ($decoded['contracts'] as $c) {
            $this->assertArrayHasKey('contract', $c);
            $this->assertArrayHasKey('active', $c);
            $this->assertArrayHasKey('implementations', $c);
        }
    }

    public function test_contracts_list_table_mentions_contract(): void
    {
        $app = new Application();
        $command = $app->find('contracts:list');

        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $code = $command->run($input, $output);
        $this->assertSame(0, $code);

        $out = $output->fetch();
        $this->assertStringContainsString('Contract', $out);
        $this->assertStringContainsString('Active', $out);
    }
}
