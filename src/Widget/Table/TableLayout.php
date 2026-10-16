<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Layout\RowExtents;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;
use WeakMap;

/**
 * Row heights and wrapped cell lines, shared by rendering, hit-testing and
 * scrolling so they cannot disagree.
 *
 * Heights are held for every row, because the scrollbar and the scroll clamp
 * need the total. Wrapped lines are produced per row on demand: only the rows
 * in view are ever drawn, and keeping every cell's lines for a large table
 * costs far more memory than the table itself.
 *
 * @internal
 */
final readonly class TableLayout
{
    private RowExtents $extents;

    /**
     * @param list<int> $heights
     */
    private function __construct(public array $heights, public int $totalHeight)
    {
        $this->extents = RowExtents::measure(count($heights), static fn (int $row): int => $heights[$row]);
    }

    /**
     * @param list<int> $columnWidths
     * @param list<Row> $rows
     */
    public static function forRows(array $columnWidths, array $rows): self
    {
        $heights = [];

        foreach ($rows as $row) {
            $heights[] = self::rememberedHeightOfRow($columnWidths, $row);
        }

        return new self($heights, array_sum($heights));
    }

    /**
     * @param list<int> $columnWidths
     */
    public static function heightOfRow(array $columnWidths, Row $row): int
    {
        if ($row->spansAllColumns) {
            return count(self::spanningLines($columnWidths, $row));
        }

        $height = 1;

        foreach ($columnWidths as $columnIndex => $width) {
            $text = self::textOf($row, $columnIndex);

            // Wrapping is the expensive part and most cells do not need it, so
            // ask how wide the text is before asking where to break it.
            if ($width <= 0 || (!str_contains($text, "\n") && CharWidth::stringWidth($text) <= $width)) {
                continue;
            }

            $height = max($height, count(self::cellLines($text, $width)));
        }

        return $height;
    }

    /**
     * A cell broken at its own line breaks, then between words to fit the column.
     *
     * @return list<string>
     */
    public static function cellLines(string $cell, int $width): array
    {
        if ($width <= 0) {
            return [''];
        }

        $lines = [];
        foreach (explode("\n", $cell) as $line) {
            array_push($lines, ...TextWrap::wrap($line, $width));
        }

        return $lines;
    }

    /**
     * Wrapped lines for one row, indexed by column.
     *
     * @param list<int> $columnWidths
     *
     * @return list<list<string>>
     */
    public static function linesOfRow(array $columnWidths, Row $row): array
    {
        $lines = [];

        foreach ($columnWidths as $columnIndex => $width) {
            $lines[] = self::cellLines(self::textOf($row, $columnIndex), $width);
        }

        return $lines;
    }

    /**
     * A spanning row's label wrapped to the whole table, which is the width it
     * is drawn across.
     *
     * @param list<int> $columnWidths
     *
     * @return list<string>
     */
    public static function spanningLines(array $columnWidths, Row $row): array
    {
        return self::cellLines(self::textOf($row, 0), max(1, array_sum($columnWidths)));
    }

    public function heightOf(int $row): int
    {
        return $this->heights[$row] ?? 1;
    }

    /**
     * The line a row starts on, so a scrollbar counting wrapped lines can say
     * where among them the reader is.
     */
    public function lineOf(int $row): int
    {
        return $this->extents->startOf(max(0, $row));
    }

    /**
     * Which row covers the given line of the viewport, counting from the first visible row.
     */
    public function rowAtLine(int $firstRow, int $line): ?int
    {
        if ($firstRow >= count($this->heights)) {
            return null;
        }

        return $this->extents->rowAtLine($this->lineOf($firstRow) + max(0, $line));
    }

    public function firstRowAtOrAfter(int $line): int
    {
        return $this->extents->firstRowAtOrAfter($line);
    }

    /**
     * The furthest a table can scroll and still fill its viewport with its last rows.
     */
    public function maxOffset(int $viewportHeight): int
    {
        $offset = count($this->heights);
        $used = 0;

        while ($offset > 0) {
            $height = $this->heights[$offset - 1];

            if ($used + $height > $viewportHeight) {
                break;
            }

            $used += $height;
            --$offset;
        }

        return min($offset, max(0, count($this->heights) - 1));
    }

    /**
     * A host that rebuilds its table every frame hands back the same Row
     * objects, and a Row cannot change, so its height at given widths is
     * remembered. Two layouts are kept per row: the full width and the one
     * beside a scrollbar.
     *
     * @param list<int> $columnWidths
     */
    private static function rememberedHeightOfRow(array $columnWidths, Row $row): int
    {
        /** @var WeakMap<Row, array<string, int>>|null $measured */
        static $measured = null;
        $measured ??= new WeakMap();

        $key = implode(',', $columnWidths);
        /** @var array<string, int> $known */
        $known = $measured[$row] ?? [];

        if (isset($known[$key])) {
            return $known[$key];
        }

        if (count($known) >= 2) {
            unset($known[array_key_first($known)]);
        }

        $known[$key] = self::heightOfRow($columnWidths, $row);
        $measured[$row] = $known;

        return $known[$key];
    }

    private static function textOf(Row $row, int $columnIndex): string
    {
        $cell = $row->cells[$columnIndex] ?? '';

        return $cell instanceof Span ? $cell->content : $cell;
    }
}
