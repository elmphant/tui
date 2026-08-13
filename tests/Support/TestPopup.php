<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\FloatTarget;
use Elmphant\Tui\Widget\PointerFloating;

/**
 * A float that draws one marker character over a fixed rect, for tests that
 * need a pointer target without a real Dropdown.
 */
final class TestPopup implements PointerFloating
{
    private readonly FloatTarget $target;

    public function __construct(
        private readonly Rect $placement,
        private readonly string $marker = 'P',
    ) {
        $this->target = new FloatTarget();
    }

    public function target(): FloatTarget
    {
        return $this->target;
    }

    public function captures(MouseAction $action): bool
    {
        return $action === MouseAction::Press
            || $action === MouseAction::WheelUp
            || $action === MouseAction::WheelDown;
    }

    public function placement(Rect $anchor, Rect $viewport): Rect
    {
        return $this->placement;
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact($this->placement->height), Constraint::exact($this->placement->height));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putStyled($area->x, $area->y, $this->marker, $context->theme->base);
    }
}
