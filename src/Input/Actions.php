<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

final class Actions
{
    public const FOCUS_NEXT = 'focus_next';
    public const FOCUS_PREV = 'focus_prev';
    public const SELECT = 'select';
    public const TOGGLE = 'toggle';

    public const PAGE_UP = 'page_up';
    public const PAGE_DOWN = 'page_down';
    public const HOME = 'home';
    public const END = 'end';
    public const SCROLL_UP = 'scroll_up';
    public const SCROLL_DOWN = 'scroll_down';

    public const CURSOR_LEFT = 'cursor_left';
    public const CURSOR_RIGHT = 'cursor_right';

    public const FOCUS_CYCLE = 'focus_cycle';
    public const FOCUS_PREV_CYCLE = 'focus_prev_cycle';
    public const TAB_NEXT = 'tab_next';
    public const TAB_PREV = 'tab_prev';

    public const LINE_START = 'line_start';
    public const LINE_END = 'line_end';
    public const DELETE_CHAR_BACKWARD = 'delete_char_backward';
    public const DELETE_CHAR_FORWARD = 'delete_char_forward';
    public const DELETE_WORD_BACKWARD = 'delete_word_backward';
    public const DELETE_WORD_FORWARD = 'delete_word_forward';
}
