<?php

declare(strict_types=1);

namespace Elmphant\Tui\Terminal;

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

    /**
     * Whether a contrast ratio worked out here still holds on the screen. The
     * sixteen palette colours are the terminal's to define, so a colour sent as
     * one of them arrives as a shade nobody here can measure: a widget that must
     * stay readable there has to say so with an attribute, not a colour.
     */
    public function keepsContrast(): bool
    {
        return match ($this) {
            self::TrueColor, self::Ansi256 => true,
            self::Ansi, self::Ascii => false,
        };
    }
}
