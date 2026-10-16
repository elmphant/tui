<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;

/**
 * Queued with RenderContext::float() during render, drawn after the tree, unclipped.
 * Never implemented by the widget doing the queuing — the drain would render it twice.
 */
interface Floating
{
    public function placement(Rect $anchor, Rect $viewport): Rect;

    public function render(Frame $frame, Rect $area, RenderContext $context): void;
}
