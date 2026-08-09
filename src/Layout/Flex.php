<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Layout\Axis\Justification;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use InvalidArgumentException;

/**
 * Immutable, terminal-oriented flex layout.
 *
 * Items are packed on the main axis from an explicit basis, grow or shrink
 * within their bounds, and may wrap onto additional lines. Cross-axis lines
 * stretch to consume the supplied Rect so child widgets always remain bounded.
 */
final readonly class Flex implements Widget, WidgetContainer, MeasureHeight
{
    /**
     * @param list<FlexItem> $items
     */
    private function __construct(
        private Direction $direction,
        private array $items = [],
        private bool $wrap = false,
        private int $gap = 0,
        private Justification $justify = Justification::Start,
    ) {
    }

    public static function row(): self
    {
        return new self(Direction::Horizontal);
    }

    public static function column(): self
    {
        return new self(Direction::Vertical);
    }

    public function withWrap(bool $wrap = true): self
    {
        return $wrap === $this->wrap
            ? $this
            : new self($this->direction, $this->items, $wrap, $this->gap, $this->justify);
    }

    public function withGap(int $gap): self
    {
        if ($gap < 0) {
            throw new InvalidArgumentException('Flex gap must be non-negative');
        }

        return $gap === $this->gap
            ? $this
            : new self($this->direction, $this->items, $this->wrap, $gap, $this->justify);
    }

    public function withJustify(Justification $justify): self
    {
        return $justify === $this->justify
            ? $this
            : new self($this->direction, $this->items, $this->wrap, $this->gap, $justify);
    }

    public function add(
        Widget $widget,
        int $basis = 0,
        int $min = 0,
        int $max = PHP_INT_MAX,
        int $grow = 1,
        int $shrink = 1,
    ): self {
        if ($basis < 0 || $min < 0 || $max < 0 || $grow < 0 || $shrink < 0) {
            throw new InvalidArgumentException('Flex item sizes and weights must be non-negative');
        }
        if ($max < $min) {
            throw new InvalidArgumentException('Flex item max must be greater than or equal to min');
        }

        $items = $this->items;
        $items[] = new FlexItem($widget, $basis, $min, $max, $grow, $shrink);

        return new self($this->direction, $items, $this->wrap, $this->gap, $this->justify);
    }

    public function constraint(): Constraint
    {
        return Constraint::flex();
    }

    public function measureHeight(int $width): int
    {
        if ($width < 1 || $this->items === []) {
            return 0;
        }

        if ($this->direction === Direction::Vertical) {
            $height = 0;
            foreach ($this->items as $item) {
                $height += $item->initialSize();
            }

            return $height + $this->gap * (count($this->items) - 1);
        }

        $lines = $this->packLines($width);
        $height = $this->gap * (count($lines) - 1);

        foreach ($lines as $line) {
            $placements = $this->resolveMainPlacements($line, $width);
            $lineHeight = 0;
            foreach ($placements as [$itemIndex, , $itemWidth]) {
                $lineHeight = max($lineHeight, $this->intrinsicHeight($this->items[$itemIndex]->widget, $itemWidth));
            }
            $height += $lineHeight;
        }

        return $height;
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function computeChildRects(Rect $area): array
    {
        if ($this->items === []) {
            return [];
        }

        $isRow = $this->direction === Direction::Horizontal;
        $mainSize = $isRow ? $area->width : $area->height;
        $crossSize = $isRow ? $area->height : $area->width;
        $lines = $this->packLines($mainSize);
        $lineGap = $this->effectiveGap(count($lines), $crossSize);
        $crossAvailable = $crossSize - $lineGap * (count($lines) - 1);

        $linePlacements = [];
        $desiredCrossSizes = [];
        foreach ($lines as $line) {
            $placements = $this->resolveMainPlacements($line, $mainSize);
            $linePlacements[] = $placements;

            if (!$isRow) {
                $desiredCrossSizes[] = 1;
                continue;
            }

            $desired = 0;
            foreach ($placements as [$itemIndex, , $itemWidth]) {
                $desired = max($desired, $this->intrinsicHeight($this->items[$itemIndex]->widget, $itemWidth));
            }
            $desiredCrossSizes[] = $desired;
        }

        $lineCrossSizes = self::fitSizes($desiredCrossSizes, $crossAvailable);
        $result = [];
        $crossCursor = 0;

        foreach ($linePlacements as $lineIndex => $placements) {
            $lineCrossSize = $lineCrossSizes[$lineIndex];

            foreach ($placements as [$itemIndex, $mainOffset, $itemMainSize]) {
                $item = $this->items[$itemIndex];
                $childArea = $isRow
                    ? new Rect($area->x + $mainOffset, $area->y + $crossCursor, $itemMainSize, $lineCrossSize)
                    : new Rect($area->x + $crossCursor, $area->y + $mainOffset, $lineCrossSize, $itemMainSize);
                $result[] = [$item->widget, $childArea];
            }

            $crossCursor += $lineCrossSize + $lineGap;
        }

        return $result;
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function childWidgets(Rect $area): array
    {
        return $this->computeChildRects($area);
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        foreach ($this->items as $item) {
            yield $item->widget;
        }
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach ($context->childrenOf($this, $area) as [$child, $childArea]) {
            $child->render($frame, $childArea, $context);
        }
    }

    /**
     * @return list<list<int>> item indexes grouped into main-axis lines
     */
    private function packLines(int $mainSize): array
    {
        if (!$this->wrap) {
            return [array_keys($this->items)];
        }

        $lines = [];
        $line = [];
        $used = 0;

        foreach ($this->items as $index => $item) {
            $size = $item->initialSize();
            if ($line === []) {
                $line[] = $index;
                $used = $size;
                continue;
            }

            $candidate = self::saturatingSum([$used, $this->gap, $size]);
            $doesNotFit = $candidate > $mainSize;

            if ($doesNotFit) {
                $lines[] = $line;
                $line = [$index];
                $used = $size;
            } else {
                $line[] = $index;
                $used = $candidate;
            }
        }

        if ($line !== []) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param list<int> $line
     *
     * @return list<array{int, int, int}> [item index, main offset, main size]
     */
    private function resolveMainPlacements(array $line, int $mainSize): array
    {
        $count = count($line);
        $gap = $this->effectiveGap($count, $mainSize);
        $available = $mainSize - $gap * ($count - 1);
        /** @var array<int, int> $sizes */
        $sizes = [];

        foreach ($line as $itemIndex) {
            $sizes[$itemIndex] = $this->items[$itemIndex]->initialSize();
        }

        $used = self::saturatingSum($sizes);
        if (self::sumExceeds($sizes, PHP_INT_MAX)) {
            $forced = self::fitSizes(
                array_map(static fn (int $itemIndex): int => $sizes[$itemIndex], $line),
                $available,
            );
            foreach ($line as $lineIndex => $itemIndex) {
                $sizes[$itemIndex] = $forced[$lineIndex];
            }
        } elseif ($used < $available) {
            $this->growSizes($sizes, $line, $available - $used);
        } elseif (self::sumExceeds($sizes, $available)) {
            $this->shrinkSizes($sizes, $line, $used - $available, true);
            if (self::sumExceeds($sizes, $available)) {
                $this->shrinkSizes($sizes, $line, self::saturatingSum($sizes) - $available, false);
            }
            if (self::sumExceeds($sizes, $available)) {
                // Terminal safety is stronger than a no-shrink preference:
                // children must never receive Rects outside their container.
                $forced = self::fitSizes(
                    array_map(static fn (int $itemIndex): int => $sizes[$itemIndex], $line),
                    $available,
                );
                foreach ($line as $lineIndex => $itemIndex) {
                    $sizes[$itemIndex] = $forced[$lineIndex];
                }
            }
        }

        $unused = $available - array_sum($sizes);
        [$leading, $gapExtras] = $this->justify($count, $unused);
        $placements = [];
        $cursor = $leading;

        foreach ($line as $lineIndex => $itemIndex) {
            if ($lineIndex > 0) {
                $cursor += $gap + ($gapExtras[$lineIndex - 1] ?? 0);
            }
            $size = $sizes[$itemIndex];
            $placements[] = [$itemIndex, $cursor, $size];
            $cursor += $size;
        }

        return $placements;
    }

    /**
     * @param array<int, int> $sizes
     *
     * @param-out array<int, int> $sizes
     *
     * @param list<int> $line
     */
    private function growSizes(array &$sizes, array $line, int $free): void
    {
        for ($round = 0, $maxRounds = count($line) + 1; $free > 0 && $round < $maxRounds; ++$round) {
            $eligible = array_filter(
                $line,
                fn (int $index): bool => $this->items[$index]->grow > 0
                    && $sizes[$index] < $this->items[$index]->max,
            );
            if ($eligible === []) {
                return;
            }

            $totalWeight = array_sum(array_map(fn (int $index): int => $this->items[$index]->grow, $eligible));
            $roundFree = $free;
            foreach ($eligible as $index) {
                $headroom = $this->items[$index]->max - $sizes[$index];
                $grant = min($headroom, (int)floor($roundFree * ($this->items[$index]->grow / $totalWeight)));
                $sizes[$index] += $grant;
                $free -= $grant;
            }

            if ($free === $roundFree) {
                foreach (array_reverse($eligible) as $index) {
                    if ($free === 0) {
                        break;
                    }
                    ++$sizes[$index];
                    --$free;
                }
            }
        }
    }

    /**
     * @param array<int, int> $sizes
     *
     * @param-out array<int, int> $sizes
     *
     * @param list<int> $line
     */
    private function shrinkSizes(array &$sizes, array $line, int $deficit, bool $respectMin): void
    {
        for ($round = 0, $maxRounds = count($line) + 1; $deficit > 0 && $round < $maxRounds; ++$round) {
            $eligible = array_filter(
                $line,
                function (int $index) use ($sizes, $respectMin): bool {
                    $floor = $respectMin ? $this->items[$index]->min : 0;

                    return $this->items[$index]->shrink > 0 && $sizes[$index] > $floor;
                },
            );
            if ($eligible === []) {
                return;
            }

            $weights = [];
            foreach ($eligible as $index) {
                $weights[$index] = (float)$this->items[$index]->shrink * $sizes[$index];
            }
            $totalWeight = array_sum($weights);
            $roundDeficit = $deficit;

            foreach ($eligible as $index) {
                $floor = $respectMin ? $this->items[$index]->min : 0;
                $capacity = $sizes[$index] - $floor;
                $take = min($capacity, (int)floor($roundDeficit * ($weights[$index] / $totalWeight)));
                $sizes[$index] -= $take;
                $deficit -= $take;
            }

            if ($deficit === $roundDeficit) {
                foreach (array_reverse($eligible) as $index) {
                    if ($deficit === 0) {
                        break;
                    }
                    $floor = $respectMin ? $this->items[$index]->min : 0;
                    if ($sizes[$index] > $floor) {
                        --$sizes[$index];
                        --$deficit;
                    }
                }
            }
        }
    }

    /**
     * @return array{int, list<int>} leading cells and per-gap extra cells
     */
    private function justify(int $count, int $unused): array
    {
        if ($unused === 0 || $this->justify === Justification::Start) {
            return [0, []];
        }

        if ($this->justify === Justification::End) {
            return [$unused, []];
        }

        if ($this->justify === Justification::Center) {
            return [intdiv($unused, 2), []];
        }

        if ($this->justify === Justification::SpaceBetween) {
            return [0, self::spread($unused, $count - 1)];
        }

        $slots = self::spread($unused, $count + 1);
        $gapExtras = array_slice($slots, 1);

        return [$slots[0], $gapExtras];
    }

    /**
     * @return list<int>
     */
    private static function spread(int $amount, int $slots): array
    {
        if ($slots < 1) {
            return [];
        }

        $share = intdiv($amount, $slots);
        $remainderStartsAt = $slots - ($amount % $slots);
        $sizes = [];
        for ($index = 0; $index < $slots; ++$index) {
            $sizes[] = $share + ($index >= $remainderStartsAt ? 1 : 0);
        }

        return $sizes;
    }

    /**
     * @param list<int> $desired
     *
     * @return list<int>
     */
    private static function fitSizes(array $desired, int $available): array
    {
        if ($desired === []) {
            return [];
        }

        $total = self::saturatingSum($desired);
        if ($total <= $available) {
            $extra = self::spread($available - $total, count($desired));
            foreach ($desired as $index => $size) {
                $desired[$index] = $size + $extra[$index];
            }

            return $desired;
        }

        $floatTotal = array_sum($desired);
        $fitted = [];
        $used = 0;
        foreach ($desired as $index => $size) {
            $fitted[$index] = (int)floor(($size / $floatTotal) * $available);
            $used += $fitted[$index];
        }

        $remainder = $available - $used;
        foreach (array_reverse(array_keys($fitted)) as $index) {
            if ($remainder === 0) {
                break;
            }
            ++$fitted[$index];
            --$remainder;
        }

        return array_values($fitted);
    }

    /**
     * @param array<int, int> $sizes
     */
    private static function saturatingSum(array $sizes): int
    {
        $total = 0;
        foreach ($sizes as $size) {
            if ($size > PHP_INT_MAX - $total) {
                return PHP_INT_MAX;
            }
            $total += $size;
        }

        return $total;
    }

    /**
     * @param array<int, int> $sizes
     */
    private static function sumExceeds(array $sizes, int $available): bool
    {
        $remaining = $available;
        foreach ($sizes as $size) {
            if ($size > $remaining) {
                return true;
            }
            $remaining -= $size;
        }

        return false;
    }

    private function effectiveGap(int $count, int $available): int
    {
        if ($count < 2) {
            return 0;
        }

        return min($this->gap, intdiv(max(0, $available), $count - 1));
    }

    private function intrinsicHeight(Widget $widget, int $width): int
    {
        if ($width < 1) {
            return 0;
        }
        if ($widget instanceof MeasureHeight) {
            return max(0, $widget->measureHeight($width));
        }

        $constraint = $widget->constraint();
        if ($constraint->min === $constraint->max) {
            return max(0, $constraint->min);
        }

        return max(1, $constraint->min);
    }
}
