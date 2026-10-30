<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;

/**
 * Expose the visible child area for hit-testing when scrolling places children outside the container.
 */
interface ClipsItsChildren extends WidgetContainer
{
    /**
     * Pinned rows use their own visible area, not the scrolling region.
     */
    public function viewport(Rect $area, Widget $child): Rect;
}
