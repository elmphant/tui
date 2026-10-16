<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Layout\Rect;

/**
 * Geometry resolved before drawing, so a container draws its children where
 * input will find them instead of laying them out a second time.
 */
interface PlannedLayout
{
    /**
     * @return list<array{object, Rect}>|null null where the container was not planned at this area
     */
    public function childrenOf(object $container, Rect $area): ?array;
}
