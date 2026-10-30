<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * @internal
 */
enum Distribution
{
    /**
     * Reserve minimums before dividing the remaining space by weight.
     */
    case AfterReservingFloors;

    /**
     * Divide all space by weight, then raise slots below their minimums.
     */
    case AcrossTheWholeAxis;
}
