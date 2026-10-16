<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;

/**
 * A container that places children outside the part of itself it draws — a
 * pane scrolled past them — so the pointer can find what is actually on screen.
 */
interface ClipsItsChildren extends WidgetContainer
{
    public function viewport(Rect $area): Rect;
}
