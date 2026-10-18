<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Layout;

/**
 * Where an item sits across a flex line that is bigger than it needs.
 */
enum CrossAlignment
{
    case Stretch;
    case Start;
    case Center;
    case End;
}
