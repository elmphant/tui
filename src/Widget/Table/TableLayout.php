<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Layout\RowExtents;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;
use Elmphant\Tui\Widget\Wrap;
use WeakMap;

/**
 * Cache all row heights for scrollbar totals, but wrap cell lines on demand to limit memory use.
 * Share these measurements between rendering, hit-testing and scrolling.
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
     * @param list<int>  $columnWidths
     * @param list<Row>  $rows
     * @param list<Wrap> $wraps        per column, word wrap where missing
     * @param ?int       $spanWidth    what a spanning row is drawn across, the gaps between columns included
     */
    public static function forRows(array $columnWidths, array $rows, array $wraps = [], ?int $spanWidth = null): self
    {
        $heights = [];
        $spanWidth ??= array_sum($columnWidths);

        foreach ($rows as $row) {
            $heights[] = self::rememberedHeightOfRow($columnWidths, $wraps, $spanWidth, $row);
        }

        return new self($heights, array_sum($heights));
    }

    /**
     * @return list<string>
     */
    public static function cellLines(string $cell, int $width, Wrap $wrap = Wrap::WordWrap): array
    {
        $lines = [];
        foreach (explode("\n", $cell) as $line) {
            if ($wrap === Wrap::NoWrap) {
                $lines[] = TextWrap::ellipsize($line, $width);
                continue;
            }

            array_push($lines, ...TextWrap::wrap($line, $width));
        }

        return $lines;
    }

    /**
     * @param list<int>  $columnWidths
     * @param list<Wrap> $wraps
     *
     * @return list<list<string>>
     */
    public static function linesOfRow(array $columnWidths, Row $row, array $wraps = []): array
    {
        $lines = [];

        foreach ($columnWidths as $columnIndex => $width) {
            $lines[] = self::cellLines(self::textOf($row, $columnIndex), $width, $wraps[$columnIndex] ?? Wrap::WordWrap);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public static function spanningLines(int $spanWidth, Row $row): array
    {
        return self::cellLines(self::textOf($row, 0), max(1, $spanWidth));
    }

    public function heightOf(int $row): int
    {
        return $this->heights[$row] ?? 1;
    }

    public function lineOf(int $row): int
    {
        return $this->extents->startOf($row);
    }

    public function rowAtLine(int $firstRow, int $line): ?int
    {
        return $this->extents->rowAtLine($this->lineOf($firstRow) + $line);
    }

    public function firstRowAtOrAfter(int $line): int
    {
        return $this->extents->firstRowAtOrAfter($line);
    }

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
     * Cache two layouts per immutable Row: full width and width beside a scrollbar.
     *
     * @infection-ignore-all a memo: the height is the same without it, only slower
     *
     * @param list<int>  $columnWidths
     * @param list<Wrap> $wraps
     */
    private static function rememberedHeightOfRow(array $columnWidths, array $wraps, int $spanWidth, Row $row): int
    {
        /** @var WeakMap<Row, array<string, int>>|null $measured */
        static $measured = null;
        $measured ??= new WeakMap();

        $key = implode(',', $columnWidths) . '|' . implode(',', array_map(static fn (Wrap $wrap): string => $wrap->name, $wraps)) . '|' . $spanWidth;
        /** @var array<string, int> $known */
        $known = $measured[$row] ?? [];

        if (isset($known[$key])) {
            return $known[$key];
        }

        if (count($known) >= 2) {
            unset($known[array_key_first($known)]);
        }

        $known[$key] = self::heightOfRow($columnWidths, $wraps, $spanWidth, $row);
        $measured[$row] = $known;

        return $known[$key];
    }

    /**
     * @param list<int>  $columnWidths
     * @param list<Wrap> $wraps
     */
    private static function heightOfRow(array $columnWidths, array $wraps, int $spanWidth, Row $row): int
    {
        if ($row->spansAllColumns) {
            return count(self::spanningLines($spanWidth, $row));
        }

        $height = 1;

        foreach ($columnWidths as $columnIndex => $width) {
            $text = self::textOf($row, $columnIndex);

            if (($wraps[$columnIndex] ?? Wrap::WordWrap) === Wrap::NoWrap) {
                $height = max($height, substr_count($text, "\n") + 1);
                continue;
            }

            // Check width before wrapping because most cells already fit.
            if ($width <= 0 || (!str_contains($text, "\n") && CharWidth::stringWidth($text) <= $width)) {
                continue;
            }

            $height = max($height, count(self::cellLines($text, $width)));
        }

        return $height;
    }

    private static function textOf(Row $row, int $columnIndex): string
    {
        $cell = $row->cells[$columnIndex] ?? '';

        return $cell instanceof Span ? $cell->content : $cell;
    }
}
