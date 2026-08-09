<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * Who gets the cells that no share divides evenly.
 *
 * @internal until a container lets a caller choose it
 */
enum Remainder
{
    /**
     * The rearmost slots with room take one each, so a run of equal slots keeps
     * its leading edges steady as the axis resizes.
     */
    case RearmostFirst;

    /**
     * The slots whose exact claim came closest to another whole cell take one
     * each, so the widest share is never the one left with nothing.
     */
    case StrongestClaim;
}
