<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Support\Cloneable;

/**
 * Rows count from the top of the pane's content, not the screen, so the marked
 * text stays the same while the pane scrolls or grows. A press holds the pane
 * still; a click that marks nothing gives back what it held before.
 */
final readonly class MarkedRange
{
    use Cloneable;

    /**
     * @internal get one from Panel::marked() and hand it back with withMarked()
     */
    public function __construct(
        public int $anchorRow,
        public int $anchorColumn,
        public int $endRow,
        public int $endColumn,
        public int $width,
        public bool $wasScrolledAway,
        public bool $dragging = true,
    ) {
    }

    public function withEnd(int $row, int $column): self
    {
        return $this->cloneWith(endRow: $row, endColumn: $column);
    }

    public function released(): self
    {
        return $this->cloneWith(dragging: false);
    }

    public function isEmpty(): bool
    {
        return $this->anchorRow === $this->endRow && $this->anchorColumn === $this->endColumn;
    }

    public function firstRow(): int
    {
        return min($this->anchorRow, $this->endRow);
    }

    public function lastRow(): int
    {
        return max($this->anchorRow, $this->endRow);
    }

    /**
     * @return array{int, int} the first column and the one after the last, for a row from firstRow() to lastRow()
     */
    public function columnsOn(int $row): array
    {
        [$start, $end] = $this->anchorRow < $this->endRow || ($this->anchorRow === $this->endRow && $this->anchorColumn <= $this->endColumn)
            ? [[$this->anchorRow, $this->anchorColumn], [$this->endRow, $this->endColumn]]
            : [[$this->endRow, $this->endColumn], [$this->anchorRow, $this->anchorColumn]];

        return [
            $row === $start[0] ? $start[1] : 0,
            $row === $end[0] ? $end[1] + 1 : $this->width,
        ];
    }
}
