<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * Who gets the cells that no share divides evenly.
 *
 * @internal
 */
enum Remainder
{
    /**
     * The rearmost slots with room take one each.
     */
    case RearmostFirst;

    /**
     * The slots whose claim came closest to another whole cell take one each.
     */
    case StrongestClaim;
}
