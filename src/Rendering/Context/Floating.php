<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Layout\Rect;

/**
 * Queue a separate Floating object; queuing the widget itself would render it twice.
 */
interface Floating extends Drawable
{
    public function placement(Rect $anchor, Rect $viewport): Rect;
}
