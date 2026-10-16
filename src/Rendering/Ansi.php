<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

final class Ansi
{
    public const HIDE_CURSOR = "\x1b[?25l";
    public const SHOW_CURSOR = "\x1b[?25h";
    public const CURSOR_HOME = "\x1b[H";
    public const CURSOR_RIGHT_ONE = "\x1b[C";
    public const CURSOR_DOWN_ONE = "\x1b[B";

    public const RESET_STYLE = "\x1b[0m";
    public const ERASE_TO_LINE_END = "\x1b[K";
    public const DEFAULT_CURSOR_SHAPE = "\x1b[0 q";

    public const ENTER_ALT_SCREEN = "\x1b[?1049h";
    public const LEAVE_ALT_SCREEN = "\x1b[?1049l";

    public const QUERY_KITTY_KEYBOARD = "\x1b[?u";

    /**
     * Flag 1, escape disambiguation, and nothing else: flag 2 would add a
     * release event for every press, and flag 4 alternates the parser ignores.
     */
    public const ENABLE_KITTY_KEYBOARD = "\x1b[>1u";
    public const DISABLE_KITTY_KEYBOARD = "\x1b[<u";

    public const ENABLE_BRACKETED_PASTE = "\x1b[?2004h";
    public const DISABLE_BRACKETED_PASTE = "\x1b[?2004l";

    public const ENABLE_MOUSE_BASIC = "\x1b[?1000h";
    public const ENABLE_MOUSE_MOTION = "\x1b[?1002h";
    public const ENABLE_MOUSE_SGR = "\x1b[?1006h";
    public const DISABLE_MOUSE_SGR = "\x1b[?1006l";
    public const DISABLE_MOUSE_MOTION = "\x1b[?1002l";
    public const DISABLE_MOUSE_BASIC = "\x1b[?1000l";

    public const SAVE_CURSOR = "\x1b[s";
    public const RESTORE_CURSOR = "\x1b[u";
    public const REPORT_CURSOR_POSITION = "\x1b[6n";
    public const CURSOR_TO_BOTTOM_RIGHT = "\x1b[9999;9999H";

    public const SYNC_START = "\x1b[?2026h";
    public const SYNC_END = "\x1b[?2026l";

    public static function cursorTo(int $row, int $column): string
    {
        return "\x1b[" . ($row + 1) . ';' . ($column + 1) . 'H';
    }

    public static function cursorUp(int $count): string
    {
        return "\x1b[{$count}A";
    }

    public static function cursorDown(int $count): string
    {
        return "\x1b[{$count}B";
    }

    public static function cursorRight(int $count): string
    {
        return "\x1b[{$count}C";
    }

    public static function cursorLeft(int $count): string
    {
        return "\x1b[{$count}D";
    }

    public static function cursorToRowStart(int $row): string
    {
        return "\x1b[" . ($row + 1) . ';1H';
    }

    public static function sgr(string $params): string
    {
        return "\x1b[{$params}m";
    }

    public static function cursorShape(CursorShape $shape): string
    {
        return "\x1b[" . $shape->value . ' q';
    }

    /**
     * OSC 52. The terminal at the far end of the pipe is what puts this on the
     * system clipboard, which is how it reaches a desktop from inside a container
     * or an ssh session, where a clipboard library could not.
     */
    public static function clipboard(string $text): string
    {
        return "\x1b]52;c;" . base64_encode($text) . "\x07";
    }
}
