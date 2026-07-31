<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

enum ColorProfile
{
    case Ascii;
    case Ansi;
    case Ansi256;
    case TrueColor;
}
