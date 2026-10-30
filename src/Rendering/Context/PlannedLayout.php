<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Layout\Rect;

interface PlannedLayout
{
    /**
     * @return list<array{object, Rect}>|null null where the container was not planned at this area
     */
    public function childrenOf(object $container, Rect $area): ?array;
}
