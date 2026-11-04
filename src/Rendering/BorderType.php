<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

enum BorderType
{
    case Rounded;
    case Solid;
    case Thick;
    case Double;
    case Dashed;
    case Dotted;

    public function glyphs(): BorderGlyphs
    {
        return match ($this) {
            //                                 TL   TR   BL   BR   H    V    ├    ┤    ┬    ┴    ┼
            self::Rounded => new BorderGlyphs('╭', '╮', '╰', '╯', '─', '│', '├', '┤', '┬', '┴', '┼'),
            self::Solid => new BorderGlyphs('┌', '┐', '└', '┘', '─', '│', '├', '┤', '┬', '┴', '┼'),
            self::Thick => new BorderGlyphs('┏', '┓', '┗', '┛', '━', '┃', '┣', '┫', '┳', '┻', '╋'),
            self::Double => new BorderGlyphs('╔', '╗', '╚', '╝', '═', '║', '╠', '╣', '╦', '╩', '╬'),
            self::Dashed => new BorderGlyphs('┌', '┐', '└', '┘', '╌', '╎', '├', '┤', '┬', '┴', '┼'),
            self::Dotted => new BorderGlyphs('┌', '┐', '└', '┘', '┈', '┊', '├', '┤', '┬', '┴', '┼'),
        };
    }
}
