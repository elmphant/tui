<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

/**
 * The sixteen colours every terminal has, which each user's palette decides.
 */
enum AnsiColor: int
{
    case Black = 0;
    case Red = 1;
    case Green = 2;
    case Yellow = 3;
    case Blue = 4;
    case Magenta = 5;
    case Cyan = 6;
    case White = 7;
    case BrightBlack = 8;
    case BrightRed = 9;
    case BrightGreen = 10;
    case BrightYellow = 11;
    case BrightBlue = 12;
    case BrightMagenta = 13;
    case BrightCyan = 14;
    case BrightWhite = 15;
}
