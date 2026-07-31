<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

interface Widget
{
    public function constraint(): Constraint;

    public function render(Frame $frame, Rect $area, RenderContext $context): void;
}
