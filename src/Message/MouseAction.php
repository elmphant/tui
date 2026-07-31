<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

enum MouseAction
{
    case Press;
    case Release;
    case Motion;
    case WheelUp;
    case WheelDown;
}
