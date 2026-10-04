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
    public function a_file_edited_while_the_targets_ran_keeps_the_receipt_from_holding(): void
    {
        // Review of dev#130: hashing at attach() vouched for bytes no target checked.
        $receipts = new VerifyReceipts($this->root);
        $before = $receipts->fingerprintOf(['src/Price.php', 'src/Gone.php']);
        file_put_contents($this->root . '/src/Price.php', "<?php // edited mid-run\n");
        $id = self::receiptId($receipts->attach($this->envelope('pass'), [], $before));

        $check = $receipts->check($id);
        self::assertSame(['src/Price.php'], $check['changed_during_run']);
        $receipt = $check['receipt'];
        self::assertIsArray($receipt);
        self::assertIsArray($receipt['tree'] ?? null);
        self::assertSame(hash('sha256', "<?php // v1\n"), $receipt['tree']['src/Price.php'] ?? null, 'the receipt names what the targets saw');
    }

    #[Test]
    public function a_path_that_cannot_be_read_is_not_a_missing_one(): void
    {
        // Review of dev#130: a failed read hashed to null, the same as "absent".
        $receipts = new VerifyReceipts($this->root);
        $id = self::receiptId($receipts->attach($this->envelope('pass'), []));
        mkdir($this->root . '/src/Gone.php');

        self::assertSame(['src/Gone.php'], $receipts->check($id)['changed_since']);
    }

    #[Test]
    public function an_edited_or_unreadable_receipt_is_listed_as_such_not_hidden(): void
    {
        // Review of dev#130: a failed receipt edited to "pass" disappeared from
        // the red list, and an unreadable one was skipped.
        $receipts = new VerifyReceipts($this->root);
        $edited = self::receiptId($receipts->attach(['generated_at' => gmdate(DATE_ATOM)] + $this->envelope('fail'), []));
        $file = $this->root . '/' . VerifyReceipts::DIR . '/' . $edited . '.json';
        file_put_contents($file, str_replace('"verdict": "fail"', '"verdict": "pass"', (string) file_get_contents($file)));
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/rcpt-20991231-000000-badbad.json', 'not json');

        $verdicts = array_column($receipts->unread(3600), 'verdict', 'id');
        self::assertSame('edited', $verdicts[$edited] ?? null);
        self::assertSame('unreadable', $verdicts['rcpt-20991231-000000-badbad'] ?? null);
    }

    #[Test]
    public function a_missing_receipt_is_not_found(): void
    {
        self::assertFalse((new VerifyReceipts($this->root))->check('rcpt-nope')['found']);
        self::assertFalse((new VerifyReceipts($this->root))->check(null)['found']);
    }

    #[Test]
    public function a_run_nobody_checked_is_unread_until_someone_checks_it(): void
    {
        $previous = getenv('SEMITEXA_AGENT_SESSION');
        putenv('SEMITEXA_AGENT_SESSION=claude-sub-1');
        try {
            $receipts = new VerifyReceipts($this->root);
            $red = self::receiptId($receipts->attach(['generated_at' => gmdate(DATE_ATOM)] + $this->envelope('fail'), []));
            $green = self::receiptId($receipts->attach(['generated_at' => gmdate(DATE_ATOM)] + $this->envelope('pass'), []));

            $unread = $receipts->unread(3600);
            self::assertEqualsCanonicalizing([$red, $green], array_column($unread, 'id'));
            self::assertSame(['agent_session' => 'claude-sub-1', 'trace' => null], $unread[0]['run_by'], 'who ran it travels with it');

            $receipts->markRead($red);
            self::assertSame([$green], array_column($receipts->unread(3600), 'id'));
        } finally {
            // Restore what the suite had, rather than unsetting it for every later test.
            putenv($previous === false ? 'SEMITEXA_AGENT_SESSION' : 'SEMITEXA_AGENT_SESSION=' . $previous);
        }
    }

    #[Test]
    public function a_window_leaves_out_older_runs(): void
    {
        $receipts = new VerifyReceipts($this->root);
        $receipts->attach(['generated_at' => '2026-01-01T00:00:00+00:00'] + $this->envelope('fail'), []);

        self::assertSame([], $receipts->unread(3600));
        self::assertCount(1, $receipts->unread(null));
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
