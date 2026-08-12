<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

/**
 * A Widget that renders its content at an absolute (x, y) within the given area.
 *
 * Useful for drag ghosts, tooltips, popovers — anything that should float on top
 * of normal layout at a specific position rather than flow with parent layout.
 */
final readonly class PositionedOverlay implements Widget
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

    public function content(Widget $widget): self
    {
        return $this->cloneWith(widget: $widget);
    }

    public function withSize(int $width, int $height): self
    {
        return $this->cloneWith(width: $width, height: $height);
    }

    public function constraint(): Constraint
    {
        return Constraint::flex();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($this->widget === null) {
            return;
        }

        $child = $this->widget;
        $constraint = $child->constraint();

        $width = $this->width ?? max(0, $area->width - ($this->x - $area->x));
        $height = $this->height ?? (
            $constraint->min === $constraint->max && $constraint->min > 0
                ? $constraint->min
                : max(0, $area->height - ($this->y - $area->y))
        );

        if ($width <= 0 || $height <= 0) {
            return;
        }

        $child->render($frame, new Rect($this->x, $this->y, $width, $height), $context);
    }
}
