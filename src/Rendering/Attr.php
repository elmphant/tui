<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

final class Attr
{
    public const BOLD = 1;
    public const FAINT = 2;
    public const ITALIC = 4;
    public const UNDERLINE = 8;
    public const BLINK = 16;
    public const REVERSE = 32;
    public const STRIKETHROUGH = 64;

    /**
     * @internal the code that turns each attribute on, and adding 20 turns it off
     */
    public const BY_SGR_CODE = [
        1 => self::BOLD,
        2 => self::FAINT,
        3 => self::ITALIC,
        4 => self::UNDERLINE,
        5 => self::BLINK,
        7 => self::REVERSE,
        9 => self::STRIKETHROUGH,
    ];
}
