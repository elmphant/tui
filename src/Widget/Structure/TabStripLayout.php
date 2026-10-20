<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;

final readonly class TabStripLayout
{
    public const HIDDEN_BEFORE = '‹';
    public const HIDDEN_AFTER = '›';
    private const BORDER_AND_PADDING = 4;
    private const MARKER_WIDTH = 1;
    private const NARROWEST_TAB = self::BORDER_AND_PADDING + 1;

    /**
     * @param array<int, Rect>   $cells
     * @param array<int, string> $labels
     */
    private function __construct(
        private array $cells,
        private array $labels,
        private int $columnAfterLastTab,
        private ?int $hiddenBeforeColumn,
        private ?int $hiddenAfterColumn,
    ) {
    }

    /**
     * @param list<string> $labels
     */
    public static function across(array $labels, Rect $area, int $selected): self
    {
        if ($labels === []) {
            return new self([], [], $area->x, null, null);
        }

        $last = count($labels) - 1;
        $selected = max(0, min($selected, $last));
        $widths = array_map(static fn (string $label): int => CharWidth::stringWidth($label) + self::BORDER_AND_PADDING, $labels);

        $first = 0;
        while ($first < $selected && self::needed($widths, $first, $selected, $last) > $area->width) {
            ++$first;
        }

        $end = $selected;
        $markers = true;
        if (self::needed($widths, $first, $selected, $last) > $area->width) {
            $room = $area->width - self::markersWidth($first, $selected, $last);
            if ($room < self::NARROWEST_TAB) {
                $markers = false;
                $room = $area->width;
            }

            $widths[$selected] = $room;
            $labels[$selected] = TextWrap::ellipsize($labels[$selected], $room - self::BORDER_AND_PADDING);
        } else {
            while ($end < $last && self::needed($widths, $first, $end + 1, $last) <= $area->width) {
                ++$end;
            }
        }

        $column = $area->x;
        $hiddenBefore = null;
        if ($markers && $first > 0) {
            $hiddenBefore = $column;
            $column += self::MARKER_WIDTH;
        }

        $cells = [];
        $shownLabels = [];
        for ($index = $first; $index <= $end; ++$index) {
            $cells[$index] = new Rect($column, $area->y, $widths[$index], 1);
            $shownLabels[$index] = $labels[$index];
            $column += $widths[$index];
        }

        $hiddenAfter = null;
        if ($markers && $end < $last) {
            $hiddenAfter = $column;
            $column += self::MARKER_WIDTH;
        }

        return new self($cells, $shownLabels, $column, $hiddenBefore, $hiddenAfter);
    }

    public function rectOf(int $index): ?Rect
    {
        return $this->cells[$index] ?? null;
    }

    public function labelOf(int $index): ?string
    {
        return $this->labels[$index] ?? null;
    }

    public function indexAt(int $column): ?int
    {
        foreach ($this->cells as $index => $cell) {
            if ($column >= $cell->x && $column < $cell->right()) {
                return $index;
            }
        }

        return null;
    }

    public function hiddenBeforeColumn(): ?int
    {
        return $this->hiddenBeforeColumn;
    }

    public function hiddenAfterColumn(): ?int
    {
        return $this->hiddenAfterColumn;
    }

    public function columnAfterLastTab(): int
    {
        return $this->columnAfterLastTab;
    }

    /**
     * @param list<int> $widths
     */
    private static function needed(array $widths, int $first, int $end, int $last): int
    {
        return array_sum(array_slice($widths, $first, $end - $first + 1)) + self::markersWidth($first, $end, $last);
    }

    private static function markersWidth(int $first, int $end, int $last): int
    {
        return ($first > 0 ? self::MARKER_WIDTH : 0) + ($end < $last ? self::MARKER_WIDTH : 0);
    }
}
