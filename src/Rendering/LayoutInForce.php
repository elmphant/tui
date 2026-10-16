<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Closure;
use Elmphant\Tui\Layout\Rect;

final class LayoutInForce
{
    private ?PlannedLayout $plan = null;

    /**
     * @param Closure(): void $draw
     */
    public function during(?PlannedLayout $plan, Closure $draw): void
    {
        $previous = $this->plan;
        $this->plan = $plan;

        try {
            $draw();
        } finally {
            $this->plan = $previous;
        }
    }

    /**
     * @return list<array{object, Rect}>|null
     */
    public function childrenOf(object $container, Rect $area): ?array
    {
        return $this->plan?->childrenOf($container, $area);
    }
}
