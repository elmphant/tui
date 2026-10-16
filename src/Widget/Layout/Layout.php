<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Layout;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\AxisResult;
use Elmphant\Tui\Layout\Axis\AxisSpecification;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use InvalidArgumentException;

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
        private int $gap = 0,
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

        return new self(direction: $this->direction, children: [...$this->children, $child], slotConstraints: $slots, gap: $this->gap);
    }

    /**
     * Empty cells between neighbouring children; it narrows when the area cannot
     * hold it, before any child is squeezed.
     */
    public function withGap(int $gap): self
    {
        if ($gap < 0) {
            throw new InvalidArgumentException(sprintf('Layout gap must be non-negative, got %d', $gap));
        }

        return new self(direction: $this->direction, children: $this->children, slotConstraints: $this->slotConstraints, gap: $gap);
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

        return $isVertical ? $total + $this->gap * max(0, count($this->children) - 1) : $max;
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function computeChildRects(Rect $area): array
    {
        $isVertical = $this->direction === Direction::Vertical;
        $resolved = $isVertical
            ? $this->resolved($area->height, $area->width)
            : $this->resolved($area->width);
        $start = $isVertical ? $area->y : $area->x;
        $extent = $isVertical ? $area->height : $area->width;

        $result = [];

        foreach ($this->children as $index => $child) {
            $offset = min($resolved->offsets[$index], $extent);
            $size = max(0, min($resolved->sizes[$index], $extent - $offset));

            $childArea = $isVertical
                ? new Rect($area->x, $start + $offset, $area->width, $size)
                : new Rect($start + $offset, $area->y, $size, $area->height);

            $result[] = [$child, $childArea];
        }

        return $result;
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): array
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
        foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
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
        return $this->resolved($totalSpace, $acrossExtent)->sizes;
    }

    private function resolved(int $totalSpace, ?int $acrossExtent = null): AxisResult
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

        return Allocator::resolve($policies, $totalSpace, new AxisSpecification(gap: $this->gap));
    }

    private function constraintHeight(Constraint $constraint): int
    {
        if ($constraint->min === $constraint->max) {
            return $constraint->min;
        }

        return max(1, $constraint->min);
    }
}
