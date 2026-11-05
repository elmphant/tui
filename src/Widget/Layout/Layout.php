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
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;
use InvalidArgumentException;

final readonly class Layout implements Widget, GraftsItsChildren, MeasureHeight
{
    /**
     * @var list<Widget>
     */
    private array $children;

    /**
     * Store each override with its child in add() so constraints cannot shift to another child.
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

    public function add(Widget $child, ?Constraint $constraint = null): self
    {
        $slots = $this->slotConstraints;

        if ($constraint !== null) {
            $slots[count($this->children)] = $constraint;
        }

        return new self(direction: $this->direction, children: [...$this->children, $child], slotConstraints: $slots, gap: $this->gap);
    }

    public function withGap(int $gap): self
    {
        if ($gap < 0) {
            throw new InvalidArgumentException(sprintf('Layout gap must be non-negative, got %d', $gap));
        }

        return new self(direction: $this->direction, children: $this->children, slotConstraints: $this->slotConstraints, gap: $gap);
    }

    public static function column(Widget ...$children): self
    {
        return new self(Direction::Vertical, $children);
    }

    public static function row(Widget ...$children): self
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
                // Clamp measured demand to the child's limits so reported height cannot include unusable rows.
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
     * @internal
     *
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
            $size = min($resolved->sizes[$index], $extent - $offset);

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

    public function withChildAt(int $index, Widget $child): static
    {
        if (!isset($this->children[$index])) {
            return $this;
        }

        return new self(direction: $this->direction, children: array_replace($this->children, [$index => $child]), slotConstraints: $this->slotConstraints, gap: $this->gap);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
            $index = array_search($child, $this->children, true);
            $draw = static fn () => $child->render($frame, $childArea, $context);
            \is_int($index) ? $context->withinChild($index, $draw) : $draw();
        }
    }

    /**
     * @internal sizes may exceed $totalSpace, and computeChildRects() clips them to the container.
     * Without $acrossExtent, content-sized children use their minimum because they cannot be measured.
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
