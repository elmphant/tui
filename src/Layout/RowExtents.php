<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Closure;

/**
 * Where every row of a scrollable collection sits, as prefix sums.
 *
 * Measuring each row once turns the questions a viewport asks — how tall is
 * everything, which row covers this line, how far must I scroll to show that
 * row — from a walk of the collection into arithmetic and a binary search.
 *
 * @internal
 */
final readonly class RowExtents
{
    /**
     * Line each row starts on, plus a final entry holding the total height, so
     * a row's height is always the gap to its successor.
     *
     * @param list<int> $starts
     */
    private function __construct(private array $starts)
    {
    }

    /**
     * @param Closure(int): int $heightOf
     */
    public static function measure(int $rowCount, Closure $heightOf): self
    {
        $starts = [0];
        $line = 0;

        for ($index = 0; $index < $rowCount; ++$index) {
            $line += max(0, $heightOf($index));
            $starts[] = $line;
        }

        return new self($starts);
    }

    public function rowCount(): int
    {
        return count($this->starts) - 1;
    }

    public function totalHeight(): int
    {
        return $this->starts[count($this->starts) - 1];
    }

    public function startOf(int $index): int
    {
        return $this->starts[$index] ?? $this->totalHeight();
    }

    public function heightOf(int $index): int
    {
        return isset($this->starts[$index + 1]) ? $this->starts[$index + 1] - $this->starts[$index] : 0;
    }

    /**
     * The row drawn on $line, or null past the last one.
     *
     * A zero-height row occupies no line, so the search settles on the next row
     * that does — which is the one actually painted there.
     */
    public function rowAtLine(int $line): ?int
    {
        if ($line < 0 || $line >= $this->totalHeight()) {
            return null;
        }

        $low = 0;
        $high = $this->rowCount() - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->starts[$middle] <= $line) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    /**
     * The first row that begins at or after $line.
     *
     * Containers that treat rows as atomic scroll to a row boundary rather than
     * clipping the row a raw offset lands inside; this is the row they land on.
     */
    public function firstRowAtOrAfter(int $line): int
    {
        $low = 0;
        $high = $this->rowCount();

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->starts[$middle] < $line) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    public function maxOffset(int $viewportHeight): int
    {
        return ScrollWindow::lastOffset($this->totalHeight(), $viewportHeight);
    }

    /**
     * The smallest scroll adjustment that brings $index into view.
     */
    public function offsetShowing(int $index, int $viewportHeight, int $currentOffset): int
    {
        return ScrollWindow::showing(
            $currentOffset,
            $this->startOf($index),
            $this->heightOf($index),
            $viewportHeight,
            $this->maxOffset($viewportHeight),
        );
    }
}
