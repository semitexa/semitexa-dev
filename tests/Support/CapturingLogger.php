<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Support;

use Semitexa\Core\Log\LoggerInterface;

/**
 * Test logger that records warning() calls so a test can assert a corrupt
 * record was surfaced (via StaticLoggerBridge::set) rather than silently
 * skipped. Other levels are no-ops.
 */
final class CapturingLogger implements LoggerInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $warnings = [];

    public function error(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}

    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = [$message, $context];
    }

    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
}
