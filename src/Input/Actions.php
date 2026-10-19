<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

final class Actions
{
    public const SELECT = 'select';
    public const CANCEL = 'cancel';
    public const TOGGLE = 'toggle';

    public const PAGE_UP = 'page_up';
    public const PAGE_DOWN = 'page_down';
    public const HOME = 'home';
    public const END = 'end';
    public const SCROLL_UP = 'scroll_up';
    public const SCROLL_DOWN = 'scroll_down';

    public const CURSOR_UP = 'cursor_up';
    public const CURSOR_DOWN = 'cursor_down';
    public const CURSOR_LEFT = 'cursor_left';
    public const CURSOR_RIGHT = 'cursor_right';

    public const FOCUS_CYCLE = 'focus_cycle';
    public const FOCUS_PREVIOUS_CYCLE = 'focus_previous_cycle';
    public const TAB_NEXT = 'tab_next';
    public const TAB_PREVIOUS = 'tab_previous';

    /**
     * Once bound, a filter's list or table holds the keys, and this key hands them to its field.
     */
    public const SEARCH = 'search';

    /**
     * Hands the keys back from a filter's field to its content, bound only while the field holds them.
     */
    public const LEAVE_SEARCH = 'leave_search';

    /**
     * Lists every key that works where the keyboard is.
     */
    public const HELP = 'help';

    public const WORD_LEFT = 'word_left';
    public const WORD_RIGHT = 'word_right';
    public const LINE_START = 'line_start';
    public const LINE_END = 'line_end';
    public const DELETE_CHAR_BACKWARD = 'delete_char_backward';
    public const DELETE_CHAR_FORWARD = 'delete_char_forward';
    public const DELETE_WORD_BACKWARD = 'delete_word_backward';
    public const CLEAR_LINE = 'clear_line';
    public const DELETE_WORD_FORWARD = 'delete_word_forward';

    /**
     * What an action does, in the words of a key hint.
     */
    public static function description(string $action): string
    {
        return match ($action) {
            self::SELECT => 'choose',
            self::CANCEL => 'back',
            self::HOME => 'top',
            self::END => 'bottom',
            self::CURSOR_UP => 'up',
            self::CURSOR_DOWN => 'down',
            self::CURSOR_LEFT => 'left',
            self::CURSOR_RIGHT => 'right',
            self::FOCUS_CYCLE => 'next pane',
            self::FOCUS_PREVIOUS_CYCLE => 'previous pane',
            self::TAB_NEXT => 'next tab',
            self::TAB_PREVIOUS => 'previous tab',
            self::WORD_LEFT => 'word left',
            self::WORD_RIGHT => 'word right',
            self::DELETE_CHAR_BACKWARD => 'delete back',
            self::DELETE_CHAR_FORWARD => 'delete',
            self::DELETE_WORD_BACKWARD => 'delete word back',
            self::DELETE_WORD_FORWARD => 'delete word',
            self::CLEAR_LINE => 'clear',
            self::HELP => 'keys',
            self::LEAVE_SEARCH => 'back',
            default => str_replace('_', ' ', $action),
        };
    }
}
