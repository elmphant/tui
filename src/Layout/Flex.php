<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\AxisSpecification;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Layout\Axis\Justification;
use Elmphant\Tui\Layout\Axis\Overflow;
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
    use Cloneable;

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
        return $this->cloneWith(wrap: $wrap);
    }

    public function withGap(int $gap): self
    {
        if ($gap < 0) {
            throw new InvalidArgumentException('Flex gap must be non-negative');
        }

        return $this->cloneWith(gap: $gap);
    }

    public function withJustify(Justification $justify): self
    {
        return $this->cloneWith(justify: $justify);
    }

    public function add(Widget $widget, ?Constraint $constraint = null): self
    {
        $items = $this->items;
        $items[] = new FlexItem($widget, $constraint ?? Constraint::flex());

        return new self($this->direction, $items, $this->wrap, $this->gap, $this->justify);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
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
            $placements = $this->resolveMainPlacements($line, $mainSize, $crossAvailable);
            $linePlacements[] = $placements;

            $desired = 0;
            foreach ($placements as [$itemIndex, , $itemMainSize]) {
                $widget = $this->items[$itemIndex]->widget;
                $desired = max($desired, $isRow
                    ? $this->intrinsicHeight($widget, $itemMainSize)
                    : $this->intrinsicWidth($widget));
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
    private function resolveMainPlacements(array $line, int $mainSize, ?int $acrossExtent = null): array
    {
        $policies = [];
        foreach ($line as $itemIndex) {
            $policies[] = $this->policyFor($this->items[$itemIndex], $acrossExtent);
        }

        // Terminal safety is stronger than a no-shrink preference: children must
        // never receive Rects outside their container.
        $resolved = Allocator::resolve($policies, $mainSize, new AxisSpecification(
            gap: $this->gap,
            justification: $this->justify,
            overflow: Overflow::SqueezeToFloor,
        ));

        $placements = [];
        foreach ($line as $lineIndex => $itemIndex) {
            $placements[] = [$itemIndex, $resolved->offsets[$lineIndex], $resolved->sizes[$lineIndex]];
        }

        return $placements;
    }

    /**
     * A slot that declares no basis is asking for its content, so a column
     * measures the child to find out how much that is.
     */
    private function policyFor(FlexItem $item, ?int $acrossExtent): ItemPolicy
    {
        $constraint = $item->constraint;
        $policy = $constraint->policy();

        if ($this->direction === Direction::Horizontal
            || $constraint->basis !== null
            || null === $acrossExtent || $acrossExtent < 1
        ) {
            return $policy;
        }

        return new ItemPolicy(
            basis: max($policy->min, $this->intrinsicHeight($item->widget, $acrossExtent)),
            min: $policy->min,
            max: $policy->max,
            grow: $policy->grow,
            shrink: $policy->shrink,
        );
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

    private function effectiveGap(int $count, int $available): int
    {
        if ($count < 2) {
            return 0;
        }

        return min($this->gap, intdiv(max(0, $available), $count - 1));
    }

    private function intrinsicWidth(Widget $widget): int
    {
        $columns = $widget->sizing()->columns;

        return max(1, $columns->intrinsic());
    }

    private function intrinsicHeight(Widget $widget, int $width): int
    {
        if ($width < 1) {
            return 0;
        }
        if ($widget instanceof MeasureHeight) {
            return max(0, $widget->measureHeight($width));
        }

        $constraint = $widget->sizing()->rows;
        if ($constraint->min === $constraint->max) {
            return max(0, $constraint->min);
        }

        return max(1, $constraint->min);
    }
}
