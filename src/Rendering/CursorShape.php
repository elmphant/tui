<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

enum CursorShape: int
{
    case BlinkingBlock = 1;
    case SteadyBlock = 2;
    case BlinkingUnderline = 3;
    case SteadyUnderline = 4;
    case BlinkingBar = 5;
    case SteadyBar = 6;
}
