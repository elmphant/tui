<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * @internal
 */
enum Overflow
{
    /**
     * Slots shrink by their weight and stop at their floors.
     */
    case Compress;

    /**
     * Sizes stand, and the caller is told how far they overrun.
     */
    case Retain;

    /**
     * Earlier slots keep their size and the last ones are cut.
     */
    case ClipTail;

    /**
     * Allow shrinking below minimum sizes.
     */
    case SqueezeToFloor;
}
