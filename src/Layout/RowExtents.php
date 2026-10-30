<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Closure;

/**
 * Cache row prefix sums so viewport queries use arithmetic and binary search instead of measuring every row.
 *
 * @internal
 */
final readonly class RowExtents
{
    /**
     * Include the total height as the last entry so adjacent offsets give each row's height.
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
     * Skip zero-height rows because they occupy no visible line.
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
     * Scroll to a row boundary instead of clipping through a row.
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
