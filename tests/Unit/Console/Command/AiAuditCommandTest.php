<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiAuditCommand;
use Semitexa\Dev\Application\Service\Ai\State\AuditRecord;
use Semitexa\Dev\Application\Service\Ai\State\AuditStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

final class AiAuditCommandTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-audit-' . uniqid('', true);
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{int, array<string, mixed>}
     */
    private function audit(array $input): array
    {
        $command = new AiAuditCommand();
        PropertyInjector::inject($command, new ArrayContainer([AuditStore::class => new AuditStore()]));
        $tester = new CommandTester($command);
        $status = $tester->execute($input, ['interactive' => false]);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);

        return [$status, $result];
    }

    public function test_a_record_says_who_looked_at_what_and_when(): void
    {
        [$status, $out] = $this->audit(['action' => 'record', '--phase' => 'right', '--covered' => 'the Shop module', '--by' => 'architect']);

        self::assertSame(0, $status);
        self::assertSame('ok', $out['status']);
        $audit = (new AuditStore())->all()[0];
        self::assertSame(['right', 'architect', 'the Shop module', false], [$audit->phase->value, $audit->by, $audit->covered, $audit->withFramework]);
    }

    public function test_records_add_up_and_list_shows_them(): void
    {
        $this->audit(['action' => 'record', '--phase' => 'right', '--covered' => 'a']);
        $this->audit(['action' => 'record', '--phase' => 'fast', '--covered' => 'b', '--with-framework' => true]);

        [, $out] = $this->audit(['action' => 'list']);

        self::assertCount(2, $out['audits']);
        self::assertTrue($out['audits'][1]['with_framework']);
    }

    public function test_work_cannot_be_audited_clean(): void
    {
        [$status, $out] = $this->audit(['action' => 'record', '--phase' => 'work', '--covered' => 'a']);

        self::assertSame(1, $status);
        self::assertStringContainsString('right or fast', (string) $out['error']);
        self::assertSame([], (new AuditStore())->all());
    }

    public function test_an_audit_must_say_what_it_covered(): void
    {
        [$status] = $this->audit(['action' => 'record', '--phase' => 'right', '--covered' => '  ']);

        self::assertSame(1, $status);
        self::assertSame([], (new AuditStore())->all());
    }

    public function test_a_line_that_does_not_read_is_passed_over(): void
    {
        $this->audit(['action' => 'record', '--phase' => 'right', '--covered' => 'a']);
        file_put_contents($this->root . '/' . AuditStore::PATH, "{\n" . '{"phase":"later","at":"x","by":"a","covered":"b"}' . "\n", FILE_APPEND);

        self::assertCount(1, (new AuditStore())->all());
    }

    public function test_a_stored_line_with_a_bad_date_is_no_audit(): void
    {
        self::assertNull(AuditRecord::fromStored(['phase' => 'right', 'at' => 'yesterday-ish', 'by' => 'a', 'covered' => 'b']));
    }

    public function test_an_unknown_action_is_an_error(): void
    {
        self::assertSame(1, $this->audit(['action' => 'erase'])[0]);
    }
}
