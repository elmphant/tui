<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * What an axis does when the slots ask for more than it has.
 *
 * Containers genuinely differ here, so this is a choice each one makes rather
 * than a rule the allocator imposes: a scroll container is meant to overrun its
 * viewport and say so, while a row of panes must never place a pane outside it.
 *
 * @internal until a container lets a caller choose it
 */
enum Overflow
{
    /**
     * Slots give up cells by their shrink weight, and stop at their floors.
     */
    case Compress;

    /**
     * Sizes stand as asked for, and the caller is told by how much they overrun.
     */
    case Retain;

    /**
     * Earlier slots keep their size and the last ones are cut, so the total fits.
     */
    case ClipTail;

    /**
     * Floors give way too, when even the floors do not fit.
     */
    case SqueezeToFloor;
}
