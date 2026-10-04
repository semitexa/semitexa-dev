<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Receipt;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiVerifyReceiptCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** --unread on a project with a known green and a known red run (review of dev#130: an empty list proved nothing). */
final class AiVerifyReceiptCommandTest extends TestCase
{
    private string $root;
    private string|false $cwd;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/verify-receipt-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/modules', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        $this->cwd = getcwd();
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function unread_lists_red_runs_before_green_ones_and_refuses_a_bad_window(): void
    {
        $receipts = new VerifyReceipts($this->root);
        $green = $receipts->attach(['generated_at' => gmdate(DATE_ATOM), 'verdict' => 'pass', 'results' => [], 'changed_files' => []], []);
        $red = $receipts->attach(['generated_at' => gmdate(DATE_ATOM, time() - 60), 'verdict' => 'fail', 'results' => [], 'changed_files' => []], []);

        $tester = new CommandTester(new AiVerifyReceiptCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['--unread' => true, '--json' => true]));
        $data = json_decode($tester->getDisplay(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['unread'] ?? null);
        $id = static function (array $envelope): string {
            $meta = $envelope['receipt'] ?? null;

            return is_array($meta) && is_string($meta['id'] ?? null) ? $meta['id'] : '';
        };
        $listed = [];
        foreach ($data['unread'] as $run) {
            $listed[] = is_array($run) ? [$run['id'] ?? null, $run['verdict'] ?? null] : null;
        }
        self::assertSame([[$id($red), 'fail'], [$id($green), 'pass']], $listed);
        self::assertSame(1, $data['failed'] ?? null);

        self::assertSame(Command::INVALID, $tester->execute(['--unread' => true, '--hours' => 'a day']));
        self::assertStringContainsString('--hours takes a whole number', $tester->getDisplay());
    }
}
