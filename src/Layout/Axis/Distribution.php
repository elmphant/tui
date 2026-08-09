<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * What the weights divide.
 *
 * @internal
 */
enum Distribution
{
    /**
     * Floors come off the top, weights divide the rest.
     */
    case AfterReservingFloors;

    /**
     * Weights divide everything, floors only raise a slot that came out short.
     */
    case AcrossTheWholeAxis;
}
