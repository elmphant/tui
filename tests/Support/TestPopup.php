<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Rendering\Context\FloatTarget;
use Elmphant\Tui\Rendering\Context\PointerFloating;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;

final readonly class TestPopup implements PointerFloating
{
    private FloatTarget $target;

    public function __construct(
        private Rect $placement,
        private string $marker = 'P',
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
