<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;

/**
 * Queue a separate Floating object; queuing the widget itself would render it twice.
 */
interface Floating
{
    public function placement(Rect $anchor, Rect $viewport): Rect;

    public function render(Frame $frame, Rect $area, RenderContext $context): void;
}
