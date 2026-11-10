<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;

/**
 * Draw content at a fixed (x, y) within the area, such as a tooltip or drag preview.
 */
final readonly class PositionedOverlay implements Widget, GraftsItsChildren
{
    use Cloneable;

    private function __construct(
        private int $x,
        private int $y,
        private ?Widget $widget,
        private ?int $width = null,
        private ?int $height = null,
    ) {
    }

    public static function at(int $x, int $y): self
    {
        return new self($x, $y, null);
    }

    public function withContent(Widget $widget): self
    {
        return $this->cloneWith(widget: $widget);
    }

    public function withSize(int $width, int $height): self
    {
        return $this->cloneWith(width: $width, height: $height);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        if ($this->widget !== null) {
            yield $this->widget;
        }
    }

    /**
     * @return iterable<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        if ($this->widget === null) {
            return;
        }

        $placement = $this->placementIn($this->widget, $area);

        if ($placement !== null) {
            yield [$this->widget, $placement];
        }
    }

    public function withChildAt(int $index, Widget $child): static
    {
        return $index === 0 && $this->widget !== null ? $this->cloneWith(widget: $child) : $this;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach (PlacedChildren::of($this, $area, $context) as $index => [$child, $childArea]) {
            $context->drawChild(\is_int($index) ? $index : null, $child, $frame, $childArea);
        }
    }

    private function placementIn(Widget $widget, Rect $area): ?Rect
    {
        $constraint = $widget->sizing()->rows;
        $width = $this->width ?? max(0, $area->width - ($this->x - $area->x));
        $height = $this->height ?? (
            $constraint->min === $constraint->max && $constraint->min > 0
                ? $constraint->min
                : max(0, $area->height - ($this->y - $area->y))
        );

        $placement = (new Rect($this->x, $this->y, max(0, $width), max(0, $height)))->intersect($area);

        return $placement->width <= 0 || $placement->height <= 0 ? null : $placement;
    }
}
