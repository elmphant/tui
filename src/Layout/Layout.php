<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\DirectionalConstraint;
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

    public function constraint(): Constraint
    {
        return Constraint::flex();
    }

    public function measureHeight(int $width): int
    {
        if ($width < 1) {
            return 0;
        }

        $isVertical = $this->direction === Direction::Vertical;
        $total = 0;
        $max = 0;
        $columnWidths = $isVertical ? [] : $this->resolveSizes($width);

        foreach ($this->children as $index => $child) {
            $childHeight = $child instanceof MeasureHeight
                ? $child->measureHeight($isVertical ? $width : $columnWidths[$index])
                : $this->constraintHeight(self::constraintFor($child, Direction::Vertical));

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
        $sizes = $this->resolveSizes($isVertical ? $area->height : $area->width);

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
     * Declared minimums can ask for more than the area holds; the surplus is cut
     * from the rearmost children so no child is placed outside it.
     *
     * @return list<int>
     */
    private function resolveSizes(int $totalSpace): array
    {
        $reserved = 0;
        $totalFlexWeight = 0;

        foreach ($this->children as $child) {
            $constraint = self::constraintFor($child, $this->direction);

            $reserved += $constraint->min;
            if ($constraint->flex > 0) {
                $totalFlexWeight += $constraint->flex;
            }
        }

        $remaining = max(0, $totalSpace - $reserved);

        $sizes = [];
        $flexUsed = 0;

        $hasFlexBudget = $totalFlexWeight > 0;
        foreach ($this->children as $index => $child) {
            $constraint = self::constraintFor($child, $this->direction);

            $size = $constraint->min;
            if ($constraint->flex > 0 && $hasFlexBudget) {
                $share = (int)($remaining * $constraint->flex / $totalFlexWeight);
                $size = min($constraint->min + $share, $constraint->max);
                $flexUsed += $size - $constraint->min;
            }
            $sizes[$index] = $size;
        }

        // Integer-division and max-clamp leftovers go to the rearmost flex
        // children that still have headroom below their max. Once leftover
        // reaches 0 every remaining grant is min(0, headroom) = 0.
        $leftover = $remaining - $flexUsed;
        foreach (array_reverse(array_keys($this->children), true) as $reverseIndex) {
            $constraint = self::constraintFor($this->children[$reverseIndex], $this->direction);
            if ($constraint->flex > 0) {
                $grant = min($leftover, $constraint->max - $sizes[$reverseIndex]);
                $sizes[$reverseIndex] += $grant;
                $leftover -= $grant;
            }
        }

        return array_values($sizes);
    }

    private function constraintHeight(Constraint $constraint): int
    {
        if ($constraint->min === $constraint->max) {
            return $constraint->min;
        }

        return max(1, $constraint->min);
    }

    private static function constraintFor(Widget $widget, Direction $direction): Constraint
    {
        return $widget instanceof DirectionalConstraint
            ? $widget->constraintFor($direction)
            : $widget->constraint();
    }
}
