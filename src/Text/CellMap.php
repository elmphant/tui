<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

/**
 * Cache grapheme measurements by content; wrapping at a new width then only needs to find row breaks.
 *
 * @internal
 */
final class CellMap
{
    private const NON_PLAIN = '/[^\x20-\x7e]/';

    private const SECOND_HALF = "\0";

    private const RETAINED_BYTES = 12_000_000;

    /**
     * The object, its strings' headers, the key and the hash bucket; measured at about 280 bytes.
     */
    private const BYTES_PER_ENTRY = 300;

    /**
     * @var array<string, self>
     */
    private static array $retained = [];

    private static int $retainedBytes = 0;

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
        self::$retainedBytes += $map->bytes();

        while (self::$retainedBytes > self::RETAINED_BYTES && count(self::$retained) > 1) {
            $oldest = array_key_first(self::$retained);
            self::$retainedBytes -= self::$retained[$oldest]->bytes();
            unset(self::$retained[$oldest]);
        }

        return $map;
    }

    public static function measures(): int
    {
        return self::$measures;
    }

    public static function clear(): void
    {
        self::$retained = [];
        self::$retainedBytes = 0;
        self::$measures = 0;
    }

    public function width(): int
    {
        return strlen($this->cells);
    }

    /**
     * Return byte offsets and lengths, dropping the space used for each break.
     * A wide grapheme in a one-cell row occupies that row alone.
     *
     * @return list<array{int, int}>
     */
    public function rowsAt(int $maxWidth): array
    {
        $rows = [];
        $rowStart = 0;
        $byteStart = 0;
        $width = strlen($this->cells);

        while (true) {
            $rowWidth = ($this->cells[$rowStart + 1] ?? '') === self::SECOND_HALF ? max($maxWidth, 2) : $maxWidth;
            if ($width - $rowStart <= $rowWidth) {
                break;
            }

            $space = strrpos(substr($this->cells, $rowStart, $rowWidth + 1), ' ');

            if ($space !== false) {
                $spaceByte = $this->byteAt($rowStart + $space);
                $rows[] = [$byteStart, $spaceByte - $byteStart];
                $byteStart = $spaceByte + 1;
                $rowStart += $space + 1;

                continue;
            }

            $end = $rowStart + $rowWidth;
            if ($this->cells[$end] === self::SECOND_HALF) {
                --$end;
            }
            $endByte = $this->byteAt($end);
            $rows[] = [$byteStart, $endByte - $byteStart];
            $byteStart = $endByte;
            $rowStart = $end;
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

    private function bytes(): int
    {
        return strlen($this->cells) + strlen($this->offsets ?? '') + self::BYTES_PER_ENTRY;
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
