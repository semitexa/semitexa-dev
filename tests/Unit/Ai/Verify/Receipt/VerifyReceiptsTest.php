<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Receipt;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\RecordingProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;

/**
 * "All tests pass" names a receipt; the receipt says what ran, against which
 * files, with which verdict, and whether any of that changed since.
 */
final class VerifyReceiptsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/verify-receipts-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/Price.php', "<?php // v1\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string, mixed> */
    private function envelope(string $verdict): array
    {
        return [
            'generated_at'  => '2026-10-04T10:00:00+00:00',
            'verdict'       => $verdict,
            'changed_files' => [['path' => 'src/Price.php'], ['path' => 'src/Gone.php']],
            'results'       => [['id' => 'phpunit:PriceTest', 'type' => 'phpunit', 'status' => $verdict, 'exit_code' => 0, 'signal' => 'OK']],
        ];
    }

    #[Test]
    public function a_pass_on_an_unchanged_tree_holds_and_names_what_ran(): void
    {
        $recorder = new RecordingProcessRunner(new class implements ProcessRunner {
            public function run(array $command, string $cwd): array
            {
                return ['exit' => 0, 'output' => 'OK (3 tests)'];
            }
        });
        $recorder->run(['vendor/bin/phpunit', 'tests/PriceTest.php'], '/w');

        $receipts = new VerifyReceipts($this->root);
        $id = self::receiptId($receipts->attach($this->envelope('pass'), $recorder->calls()));
        $check = $receipts->check($id);
        $receipt = $check['receipt'];
        self::assertIsArray($receipt);
        self::assertIsArray($receipt['processes']);
        self::assertIsArray($receipt['processes'][0]);

        self::assertSame([true, true, 'pass', []], [$check['found'], $check['intact'], $check['verdict'], $check['changed_since']]);
        self::assertSame(['src/Gone.php' => null, 'src/Price.php' => hash('sha256', "<?php // v1\n")], $receipt['tree']);
        self::assertSame(
            ['argv' => ['vendor/bin/phpunit', 'tests/PriceTest.php'], 'cwd' => '/w', 'exit' => 0, 'output_sha256' => hash('sha256', 'OK (3 tests)'), 'output_bytes' => 12],
            array_diff_key($receipt['processes'][0], ['ms' => true]),
        );
        self::assertSame($id, $receipts->check(null)['id'], 'no id means the latest run');
    }

    #[Test]
    public function an_edited_file_or_an_edited_receipt_is_reported(): void
    {
        $receipts = new VerifyReceipts($this->root);
        $id = self::receiptId($receipts->attach($this->envelope('pass'), []));

        file_put_contents($this->root . '/src/Price.php', "<?php // v2\n");
        self::assertSame(['src/Price.php'], $receipts->check($id)['changed_since']);

        $file = $this->root . '/' . VerifyReceipts::DIR . '/' . $id . '.json';
        file_put_contents($file, str_replace('"verdict": "pass"', '"verdict": "pass" ', (string) file_get_contents($file)));
        self::assertTrue($receipts->check($id)['intact'], 'whitespace is not content');
        file_put_contents($file, str_replace('"status": "pass"', '"status": "fail"', (string) file_get_contents($file)));
        self::assertFalse($receipts->check($id)['intact']);
    }

    #[Test]
    public function a_missing_receipt_is_not_found(): void
    {
        self::assertFalse((new VerifyReceipts($this->root))->check('rcpt-nope')['found']);
        self::assertFalse((new VerifyReceipts($this->root))->check(null)['found']);
    }

    /** @param array<string, mixed> $envelope */
    private static function receiptId(array $envelope): string
    {
        $meta = $envelope['receipt'] ?? null;
        self::assertIsArray($meta);
        self::assertIsString($meta['id'] ?? null);

        return $meta['id'];
    }
}
