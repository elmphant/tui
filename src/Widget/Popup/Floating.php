<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Widget;

/**
 * Queued with RenderContext::float() during render, drawn after the tree, unclipped.
 * Never implemented by the widget doing the queuing — the drain would render it twice.
 */
interface Floating extends Widget
{
    public function placement(Rect $anchor, Rect $viewport): Rect;
}
