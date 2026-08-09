<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout\Axis;

/**
 * Where the cells nobody claimed end up once every slot has its size.
 */
enum Justification
{
    case Start;
    case Center;
    case End;
    case SpaceBetween;
    case SpaceEvenly;
}
