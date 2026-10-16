<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Rendering\BorderGlyphs;
use Elmphant\Tui\Text\CharWidth;

/**
 * @internal draws the bordered grid StaticTable is made of
 */
final class TableGrid
{
    /**
     * One space either side of every cell.
     */
    private const PADDING = 2;

    /**
     * Rows are cells, or null for a rule across the table.
     *
     * $upTo stops once that many lines exist, so a small window onto a long
     * table does not pay for the rows below it. The closing rule is part of the
     * table, not of the window, so a stopped draw does not carry one.
     *
     * @param list<string>            $headers
     * @param list<list<string>|null> $rows
     * @param list<int>               $widths
     *
     * @return list<string>
     */
    public static function draw(array $headers, array $rows, array $widths, BorderGlyphs $glyphs, ?int $upTo = null): array
    {
        $lines = [self::rule($widths, $glyphs, $glyphs->topLeft, $glyphs->teeDown, $glyphs->topRight)];

        if ($headers !== []) {
            foreach (self::cells($headers, $widths, $glyphs) as $line) {
                $lines[] = $line;
            }

            $lines[] = self::rule($widths, $glyphs, $glyphs->teeRight, $glyphs->cross, $glyphs->teeLeft);
        }

        foreach ($rows as $row) {
            if (null !== $upTo && count($lines) >= $upTo) {
                return $lines;
            }

            if ($row === null) {
                $lines[] = self::rule($widths, $glyphs, $glyphs->teeRight, $glyphs->cross, $glyphs->teeLeft);

                continue;
            }

            foreach (self::cells($row, $widths, $glyphs) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = self::rule($widths, $glyphs, $glyphs->bottomLeft, $glyphs->teeUp, $glyphs->bottomRight);

        return $lines;
    }

    /**
     * @param list<int> $widths
     */
    private static function rule(array $widths, BorderGlyphs $glyphs, string $left, string $join, string $right): string
    {
        $segments = array_map(
            static fn (int $width): string => str_repeat($glyphs->horizontal, $width + self::PADDING),
            $widths,
        );

        return $left . implode($join, $segments) . $right;
    }

    /**
     * @param list<string> $cells
     * @param list<int>    $widths
     *
     * @return list<string>
     */
    private static function cells(array $cells, array $widths, BorderGlyphs $glyphs): array
    {
        $columns = [];
        $height = 1;

        foreach ($widths as $column => $width) {
            $columns[$column] = self::linesOf($cells[$column] ?? '', $width);
            $height = max($height, count($columns[$column]));
        }

        $lines = [];

        for ($row = 0; $row < $height; ++$row) {
            $line = $glyphs->vertical;

            foreach ($widths as $column => $width) {
                $line .= ' ' . self::padded($columns[$column][$row] ?? '', $width) . ' ' . $glyphs->vertical;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Broken between words, and only inside one when the word alone is wider
     * than its column.
     *
     * @return list<string>
     */
    private static function linesOf(string $cell, int $width): array
    {
        $lines = [];

        foreach (explode("\n", $cell) as $line) {
            foreach (CharWidth::wrapToWidth($line, max(1, $width)) as $wrapped) {
                foreach (self::hardWrapped($wrapped, $width) as $hardWrapped) {
                    $lines[] = $hardWrapped;
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function hardWrapped(string $line, int $width): array
    {
        if ($width < 1) {
            return [''];
        }

        $wrapped = [];

        while (CharWidth::stringWidth($line) > $width) {
            $head = CharWidth::truncateToWidth($line, $width);

            if ($head === '') {
                break;
            }

            $wrapped[] = $head;
            $line = mb_substr($line, mb_strlen($head));
        }

        $wrapped[] = $line;

        return $wrapped;
    }

    private static function padded(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - CharWidth::stringWidth($text)));
    }
}
