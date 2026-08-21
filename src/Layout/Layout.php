<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\ContentBasis;
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

    /**
     * How a slot is sized where the parent knows better than the child. Keyed by
     * position and only ever written by add(), which appends the pair together,
     * so a constraint cannot come to describe a different child.
     *
     * @var array<int, Constraint>
     */
    private array $slotConstraints;

    /**
     * @param array<array-key, Widget> $children
     * @param array<int, Constraint>   $slotConstraints
     */
    private function __construct(
        private Direction $direction,
        array $children = [],
        array $slotConstraints = [],
    ) {
        $this->children = array_values($children);
        $this->slotConstraints = $slotConstraints;
    }

    /**
     * A child, and how much of the axis it should be given — the say a parent has
     * without reaching into the child to change what it asks for.
     */
    public function add(Widget $child, ?Constraint $constraint = null): self
    {
        $slots = $this->slotConstraints;

        if ($constraint !== null) {
            $slots[count($this->children)] = $constraint;
        }

        return new self($this->direction, [...$this->children, $child], $slots);
    }

    public static function vertical(Widget ...$children): self
    {
        return new self(Direction::Vertical, $children);
    }

    public static function horizontal(Widget ...$children): self
    {
        return new self(Direction::Horizontal, $children);
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
            $rows = $child->sizing()->rows;
            $childHeight = $child instanceof MeasureHeight
                // A measurement says what the content wants, not what the child
                // will be given: the allocator still holds it to its own bounds,
                // so measuring past them would report rows nothing can occupy.
                ? min(max($child->measureHeight($isVertical ? $width : $columnWidths[$index]), $rows->min), $rows->max)
                : $this->constraintHeight($rows);

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

        foreach ($this->children as $index => $child) {
            $constraint = $this->slotConstraints[$index] ?? $child->sizing()->on($this->direction);

            $policies[] = new ItemPolicy(
                basis: ContentBasis::of($child, $constraint, $this->direction, $acrossExtent),
                min: $constraint->min,
                max: $constraint->max,
                grow: $constraint->flex,
                shrink: $constraint->shrink,
            );
        }

        return Allocator::resolve($policies, $totalSpace)->sizes;
    }

    private function constraintHeight(Constraint $constraint): int
    {
        if ($constraint->min === $constraint->max) {
            return $constraint->min;
        }

        return max(1, $constraint->min);
    }
}
