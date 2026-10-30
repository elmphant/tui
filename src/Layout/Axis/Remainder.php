<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * @internal
 */
enum Remainder
{
    /**
     * The rearmost slots with room take one each.
     */
    case RearmostFirst;

    /**
     * Give leftover cells to slots with the largest fractional shares.
     */
    case StrongestClaim;
}
