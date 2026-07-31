<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

enum KeyEventType
{
    case Press;
    case Repeat;
    case Release;
}
