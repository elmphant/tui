<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\CharWidth;

final readonly class TabStripLayout
{
    private const BORDER_AND_PADDING = 4;

    /**
     * @param list<Rect> $cells
     */
    private function __construct(private array $cells, private int $columnAfterLastTab)
    {
    }

    /**
     * @param list<string> $labels
     */
    public static function across(array $labels, Rect $area): self
    {
        $cells = [];
        $column = $area->x;

        foreach ($labels as $label) {
            $width = CharWidth::stringWidth($label) + self::BORDER_AND_PADDING;
            if ($column + $width > $area->right()) {
                break;
            }

            $cells[] = new Rect($column, $area->y, $width, 1);
            $column += $width;
        }

        return new self($cells, $column);
    }

    public function rectOf(int $index): ?Rect
    {
        return $this->cells[$index] ?? null;
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

    public function columnAfterLastTab(): int
    {
        return $this->columnAfterLastTab;
    }
}
