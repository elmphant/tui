<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

final readonly class Separator implements Widget
{
    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $style = $context->theme->dim;
        $frame->fillRect($area->x, $area->y, $area->width, 1, $style->foregroundValue(), $style->backgroundValue(), 0, '─');
    }
}
