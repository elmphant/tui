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
    private const KEPT = 2;

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
     * @infection-ignore-all a memo of the last texts: the same without it, only slower
     */
    public static function of(string $text): self
    {
        /** @var list<array{string, self}> $known */
        static $known = [];
        foreach ($known as [$knownText, $index]) {
            if ($knownText === $text) {
                return $index;
            }
        }

        $index = self::measure($text);
        $known = [...array_slice($known, 1 - self::KEPT), [$text, $index]];

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

    private static function at(string $packed, int $index): int
    {
        /** @var array{1: int} $value */
        $value = unpack('V', $packed, 4 * $index);

        return $value[1];
    }
}
