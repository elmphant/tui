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
     * Small colour changes may quantize to the same palette entry, making fades invisible.
     */
    public function blendsSmoothly(): bool
    {
        return match ($this) {
            self::TrueColor, self::Ansi256 => true,
            self::Ansi, self::Ascii => false,
        };
    }

    /**
     * The terminal defines its 16-colour palette, so computed contrast is unreliable; use attributes for essential distinctions.
     */
    public function keepsContrast(): bool
    {
        return match ($this) {
            self::TrueColor, self::Ansi256 => true,
            self::Ansi, self::Ascii => false,
        };
    }
}
