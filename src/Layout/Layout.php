<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Layout implements Widget, WidgetContainer, MeasureHeight
{
    /**
     * @var list<Widget>
     */
    private array $children;

    private function __construct(
        private Direction $direction,
        Widget ...$children,
    ) {
        $this->children = array_values($children);
    }

    public static function vertical(Widget ...$children): self
    {
        return new self(Direction::Vertical, ...$children);
    }

    public static function horizontal(Widget ...$children): self
    {
        return new self(Direction::Horizontal, ...$children);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function measureHeight(int $width): int
    {
        if ($width < 1) {
            return 0;
        }

        $isVertical = $this->direction === Direction::Vertical;
        $total = 0;
        $max = 0;
        $columnWidths = $isVertical ? [] : $this->sizesFor($width);

        foreach ($this->children as $index => $child) {
            $childHeight = $child instanceof MeasureHeight
                ? $child->measureHeight($isVertical ? $width : $columnWidths[$index])
                : $this->constraintHeight($child->sizing()->rows);

            if ($isVertical) {
                $total += $childHeight;
            } elseif ($childHeight > $max) {
                $max = $childHeight;
            }
        }

        return $isVertical ? $total : $max;
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function computeChildRects(Rect $area): array
    {
        $isVertical = $this->direction === Direction::Vertical;
        $sizes = $isVertical
            ? $this->sizesFor($area->height, $area->width)
            : $this->sizesFor($area->width);

        $result = [];
        $cursor = $isVertical ? $area->y : $area->x;
        $unplaced = $isVertical ? $area->height : $area->width;

        foreach ($this->children as $index => $child) {
            $size = min($sizes[$index], $unplaced);
            $unplaced -= $size;

            $childArea = $isVertical
                ? new Rect($area->x, $cursor, $area->width, $size)
                : new Rect($cursor, $area->y, $size, $area->height);

            $result[] = [$child, $childArea];
            $cursor += $size;
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
     * @return list<Widget>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach ($context->childrenOf($this, $area) as [$child, $childArea]) {
            $child->render($frame, $childArea, $context);
        }
    }

    /**
     * Declared minimums can ask for more than the area holds, so these sizes can
     * sum to more than $totalSpace. It is computeChildRects() that cuts the
     * surplus, so no child is given a rect outside the area.
     *
     * $acrossExtent is the other axis; without it a content-sized child cannot
     * be measured and only its declared minimum counts.
     *
     * @return list<int>
     */
    public function sizesFor(int $totalSpace, ?int $acrossExtent = null): array
    {
        $policies = [];

        foreach ($this->children as $child) {
            $constraint = $child->sizing()->on($this->direction);

            $policies[] = new ItemPolicy(
                basis: $constraint->basis ?? $this->measuredBasis($child, $constraint, $acrossExtent),
                min: $constraint->min,
                max: $constraint->max,
                grow: $constraint->flex,
                shrink: $constraint->shrink,
            );
        }

        return Allocator::resolve($policies, $totalSpace)->sizes;
    }

    /**
     * `MeasureHeight` answers rows, so it can only fill in the basis of a
     * vertical stack; across a row it would report a height as a width.
     */
    private function measuredBasis(Widget $child, Constraint $constraint, ?int $acrossExtent): int
    {
        return match (true) {
            $this->direction === Direction::Horizontal,
            null === $acrossExtent || $acrossExtent < 1,
            $constraint->min === $constraint->max,
            !$child instanceof MeasureHeight => $constraint->min,
            default => max($constraint->min, $child->measureHeight($acrossExtent)),
        };
    }

    private function constraintHeight(Constraint $constraint): int
    {
        if ($constraint->min === $constraint->max) {
            return $constraint->min;
        }

        return max(1, $constraint->min);
    }
}
