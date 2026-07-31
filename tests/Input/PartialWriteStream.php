<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

/**
 * Stream wrapper that simulates partial writes for testing Terminal::write() backpressure handling.
 * Accepts at most $bytesPerWrite bytes per fwrite() call.
 */
final class PartialWriteStream
{
    public static int $bytesPerWrite = 3;
    public static string $written = '';
    public static bool $flushSucceeds = true;
    public static int $zeroWritesRemaining = 0;
    /**
     * @var resource|null
     */
    public static mixed $castStream = null;

    /**
     * @var resource|null
     */
    public $context;

    public static function register(): void
    {
        self::$written = '';
        self::$flushSucceeds = true;
        self::$zeroWritesRemaining = 0;
        self::$castStream = null;

        if (in_array('partialwrite', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('partialwrite');
        }
        stream_wrapper_register('partialwrite', self::class);
    }

    public static function unregister(): void
    {
        if (in_array('partialwrite', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('partialwrite');
        }
        self::$written = '';
        self::$bytesPerWrite = 3;
        self::$flushSucceeds = true;
        self::$zeroWritesRemaining = 0;
        self::$castStream = null;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$zeroWritesRemaining > 0) {
            --self::$zeroWritesRemaining;

            return 0;
        }

        $chunk = substr($data, 0, self::$bytesPerWrite);
        self::$written .= $chunk;

        return strlen($chunk);
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_flush(): bool
    {
        return self::$flushSucceeds;
    }

    /**
     * @return resource|false
     */
    public function stream_cast(int $castAs): mixed
    {
        return self::$castStream ?? false;
    }

    public function stream_stat(): array|false
    {
        return false;
    }
}
