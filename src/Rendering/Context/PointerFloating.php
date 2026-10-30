<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Message\MouseAction;

/**
 * Return an empty placement when nothing can be drawn, or the float would block input over blank cells.
 */
interface PointerFloating extends Floating
{
    /**
     * Opaque owner token; the widget that queued this float carries the same one.
     */
    public function target(): FloatTarget;

    /**
     * Only the topmost float may capture input; actions it declines go to the widget tree.
     */
    public function captures(MouseAction $action): bool;
}
