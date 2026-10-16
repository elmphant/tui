<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Message\MouseAction;

/**
 * A Floating that participates in pointer input routing.
 *
 * placement() (inherited) must return an empty Rect (width or height < 1)
 * for a float too small to draw anything — a positive-sized placement whose
 * render() paints nothing would mask input over blank cells.
 */
interface PointerFloating extends Floating
{
    /**
     * Opaque owner token; the widget that queued this float carries the same one.
     */
    public function target(): FloatTarget;

    /**
     * True only for actions this float captures while drawn. An action the
     * topmost float declines reaches the widget tree, never a lower float.
     */
    public function captures(MouseAction $action): bool;
}
