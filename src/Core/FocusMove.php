<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * How the keyboard reached where it is, carried by FocusMsg.
 */
enum FocusMove
{
    case To;
    case First;
    case Next;
    case Previous;
    case Nowhere;
}
