<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

/**
 * Distribution of unused cells on a flex line after item sizing.
 */
enum FlexJustify
{
    case Start;
    case Center;
    case End;
    case SpaceBetween;
    case SpaceEvenly;
}
