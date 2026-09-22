<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Support;

/** A real file stream with a per-handle write budget or a single failed flush. */
final class TraceWriteFaultStream
{
    /** @var resource|null Set by PHP when opening a stream wrapper. */
    public $context;
    public static ?int $writeLimit = null;
    public static bool $failFlush = false;

    /** @var resource */
    private $handle;
    private int $written = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->handle = fopen(substr($path, strlen('tracefault://')), $mode);
        return $this->handle !== false;
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return stat(substr($path, strlen('tracefault://')));
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_lock(int $operation): bool
    {
        return flock($this->handle, $operation);
    }

    public function stream_write(string $data): int|false
    {
        if (self::$writeLimit === -1) {
            return false;
        }
        if (self::$writeLimit !== null) {
            $data = substr($data, 0, max(0, self::$writeLimit - $this->written));
        }
        $written = fwrite($this->handle, $data);
        if ($written !== false) {
            $this->written += $written;
        }
        return $written;
    }

    public function stream_flush(): bool
    {
        if (self::$failFlush) {
            self::$failFlush = false;
            return false;
        }
        return fflush($this->handle);
    }

    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->handle, $size);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }
}
