<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity;

/** One change to a test that makes the suite greener without making the code more correct. */
final readonly class TestChangeFinding
{
    public const FILE_REMOVED       = 'test_file_removed';
    public const TEST_REMOVED       = 'test_removed';
    public const ASSERTIONS_REMOVED = 'assertions_removed';
    public const ASSERTION_WEAKENED = 'assertion_weakened';
    public const SKIP_ADDED         = 'skip_added';

    public function __construct(
        public string $code,
        public string $path,
        public ?string $method,
        public string $message,
        public ?string $acceptedReason = null,
    ) {}

    public function acceptedBecause(string $reason): self
    {
        return new self($this->code, $this->path, $this->method, $this->message, $reason);
    }

    /** @return array{code: string, path: string, method: ?string, message: string, accepted_reason: ?string} */
    public function toArray(): array
    {
        return [
            'code'            => $this->code,
            'path'            => $this->path,
            'method'          => $this->method,
            'message'         => $this->message,
            'accepted_reason' => $this->acceptedReason,
        ];
    }
}
