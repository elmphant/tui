<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

/**
 * Where each grapheme of a text starts, so a long field is segmented once rather than on every frame and keystroke.
 *
 * @internal
 */
final class GraphemeIndex
{
    private const RETAINED_BYTES = 16_000_000;
    // About 300 bytes measured for the object, its two strings and the cache slot.
    private const BYTES_PER_ENTRY = 300;

    /**
     * @param string $bytes   the byte each grapheme starts at, then the text's length; packed as 32-bit integers
     * @param string $columns the column each grapheme starts at, then the text's width; packed the same way
     */
    private function __construct(
        private readonly string $bytes,
        private readonly string $columns,
        public readonly int $count,
    ) {
    }

    /**
     * A field holds no index of its own: App compares widgets with ==, and a memo would make equal fields differ.
     *
     * @infection-ignore-all a memo of the texts used most recently: the same without it, only slower
     */
    public static function of(string $text): self
    {
        /** @var array<array-key, self> $known */
        static $known = [];
        static $retainedBytes = 0;

        $index = $known[$text] ?? null;
        unset($known[$text]);
        if ($index === null) {
            $index = self::measure($text);
            $retainedBytes += $index->bytes();
        }
        $known[$text] = $index;

        while ($retainedBytes > self::RETAINED_BYTES && count($known) > 1) {
            $oldest = array_key_first($known);
            $retainedBytes -= $known[$oldest]->bytes();
            unset($known[$oldest]);
        }

        return $index;
    }

    public function byteOf(int $grapheme): int
    {
        return self::at($this->bytes, $grapheme);
    }

    public function columnOf(int $grapheme): int
    {
        return self::at($this->columns, $grapheme);
    }

    public function width(): int
    {
        return $this->columnOf($this->count);
    }

    /**
     * The first grapheme that starts at or after the column, or the count when none does.
     */
    public function firstFrom(int $column): int
    {
        [$low, $high] = [0, $this->count];
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->columnOf($middle) >= $column) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }

    /**
     * The last grapheme that starts at or before the column, or 0 when none does.
     */
    public function lastUpTo(int $column): int
    {
        return $this->width() <= $column ? $this->count : max(0, $this->firstFrom($column + 1) - 1);
    }

    private static function measure(string $text): self
    {
        $bytes = [];
        $columns = [];
        $byte = 0;
        $column = 0;
        foreach (Grapheme::split($text) as $grapheme) {
            $bytes[] = $byte;
            $columns[] = $column;
            $byte += strlen($grapheme);
            $column += CharWidth::graphemeWidth($grapheme);
        }
        $bytes[] = $byte;
        $columns[] = $column;

        return new self(pack('V*', ...$bytes), pack('V*', ...$columns), count($bytes) - 1);
    }

    private function bytes(): int
    {
        return $this->byteOf($this->count) + strlen($this->bytes) + strlen($this->columns) + self::BYTES_PER_ENTRY;
    }

    private static function at(string $packed, int $index): int
    {
        /** @var array{1: int} $value */
        $value = unpack('V', $packed, 4 * $index);

        return $value[1];
    }
}
