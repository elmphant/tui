<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

enum ColorProfile
{
    case Ascii;
    case Ansi;
    case Ansi256;
    case TrueColor;

    /**
     * Whether stepping a colour by a small amount lands on a different cell
     * colour here. Sixteen colours quantise a fade back onto the shade it
     * started from, so an animation over one has nothing to show.
     */
    public function blendsSmoothly(): bool
    {
        return match ($this) {
            self::TrueColor, self::Ansi256 => true,
            self::Ansi, self::Ascii => false,
        };
    }
}
