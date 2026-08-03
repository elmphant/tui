<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\FloatTarget;
use Elmphant\Tui\Widget\PointerFloating;

/**
 * @internal
 */
final readonly class FloatPlacement
{
    public function __construct(
        public PointerFloating $float,
        public Rect $anchor,
        public Rect $placement,
    ) {
    }

    public function target(): FloatTarget
    {
        return $this->float->target();
    }

    public function contains(int $x, int $y): bool
    {
        return $this->placement->contains($x, $y);
    }
}
