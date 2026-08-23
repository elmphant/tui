<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Rect;

interface WidgetContainer
{
    /**
     * @return iterable<Widget> children in visual order, without resolving layout
     */
    public function children(): iterable;

    /**
     * @return iterable<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable;
}
