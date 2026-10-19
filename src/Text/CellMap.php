<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

/**
 * A text laid out one byte per terminal cell, so it wraps at any width by
 * looking only where a row can break.
 *
 * Splitting into graphemes and measuring them is the expensive part and does
 * not depend on the width, so a map is kept by content and serves every width.
 *
 * @internal
 */
final class CellMap
{
    private const NON_PLAIN = '/[^\x20-\x7e]/';

    private const SECOND_HALF = "\0";

    /**
     * About 10 MB: a cell costs one byte here and four in the offsets.
     */
    private const RETAINED_CELLS = 2_000_000;

    /**
     * @var array<string, self>
     */
    private static array $retained = [];

    private static int $retainedCells = 0;

    private static int $measures = 0;

    /**
     * @param string      $cells   a space for a space, the second cell of a wide grapheme as SECOND_HALF,
     *                             any other byte for the first cell of a grapheme; zero-width graphemes take none
     * @param string|null $offsets the byte each cell's grapheme starts at, packed as 32-bit integers;
     *                             null where every cell is the byte at its own offset
     */
    private function __construct(
        private readonly string $cells,
        private readonly ?string $offsets,
        private readonly int $length,
    ) {
    }

    public static function of(string $text): self
    {
        if (preg_match(self::NON_PLAIN, $text) === 0) {
            return new self($text, null, strlen($text));
        }

        $key = hash('xxh3', $text);
        if (isset(self::$retained[$key])) {
            return self::$retained[$key];
        }

        ++self::$measures;
        $map = self::measure($text);
        self::$retained[$key] = $map;
        self::$retainedCells += $map->width();

        while (self::$retainedCells > self::RETAINED_CELLS && count(self::$retained) > 1) {
            $oldest = array_key_first(self::$retained);
            self::$retainedCells -= self::$retained[$oldest]->width();
            unset(self::$retained[$oldest]);
        }

        return $map;
    }

    /**
     * How many texts were split into graphemes since the last clear.
     */
    public static function measures(): int
    {
        return self::$measures;
    }

    public static function clear(): void
    {
        self::$retained = [];
        self::$retainedCells = 0;
        self::$measures = 0;
    }

    public function width(): int
    {
        return strlen($this->cells);
    }

    /**
     * Rows no wider than $maxWidth, as byte offset and length, breaking at the
     * last space that fits and dropping it.
     *
     * A width of one cannot hold a wide grapheme, which the per-grapheme walk
     * places alone; this only answers from two cells up.
     *
     * @return list<array{int, int}>
     */
    public function rowsAt(int $maxWidth): array
    {
        $rows = [];
        $rowStart = 0;
        $byteStart = 0;
        $limit = $maxWidth;
        $width = strlen($this->cells);

        while ($width - $rowStart > $limit) {
            $space = strrpos(substr($this->cells, $rowStart, $limit + 1), ' ');

            if ($space !== false) {
                $spaceByte = $this->byteAt($rowStart + $space);
                $rows[] = [$byteStart, $spaceByte - $byteStart];
                $byteStart = $spaceByte + 1;
                // A row that opens with the space it breaks at keeps the wide
                // grapheme straddling its edge whole on the next row, one cell over.
                $limit = $space === 0 && ($this->cells[$rowStart + $maxWidth + 1] ?? '') === self::SECOND_HALF
                    ? $maxWidth + 1
                    : $maxWidth;
                $rowStart += $space + 1;

                continue;
            }

            $end = $rowStart + $limit;
            if ($this->cells[$end] === self::SECOND_HALF) {
                --$end;
            }
            $endByte = $this->byteAt($end);
            $rows[] = [$byteStart, $endByte - $byteStart];
            $byteStart = $endByte;
            $rowStart = $end;
            $limit = $maxWidth;
        }

        $rows[] = [$byteStart, $this->length - $byteStart];

        return $rows;
    }

    private static function measure(string $text): self
    {
        $cells = '';
        $offsets = [];
        $byte = 0;

        foreach (Grapheme::split($text) as $grapheme) {
            $width = CharWidth::graphemeWidth($grapheme);

            if ($width === 1) {
                $cells .= $grapheme === ' ' ? ' ' : 'x';
                $offsets[] = $byte;
            } elseif ($width === 2) {
                $cells .= 'x' . self::SECOND_HALF;
                $offsets[] = $byte;
                $offsets[] = $byte;
            }

            $byte += strlen($grapheme);
        }

        return new self($cells, pack('V*', ...$offsets), $byte);
    }

    private function byteAt(int $cell): int
    {
        if ($this->offsets === null) {
            return $cell;
        }

        /** @var array{1: int} $offset */
        $offset = unpack('V', $this->offsets, 4 * $cell);

        return $offset[1];
    }
}
