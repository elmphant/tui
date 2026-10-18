<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

enum Key
{
    case Up;
    case Down;
    case Left;
    case Right;
    case Enter;
    case Tab;
    case Escape;
    case Backspace;
    case Delete;
    case Home;
    case End;
    case PageUp;
    case PageDown;
    case Insert;
    case F1;
    case F2;
    case F3;
    case F4;
    case F5;
    case F6;
    case F7;
    case F8;
    case F9;
    case F10;
    case F11;
    case F12;

    public const NAMES = [
        'enter' => self::Enter, 'return' => self::Enter,
        'esc' => self::Escape, 'escape' => self::Escape,
        'tab' => self::Tab,
        'backspace' => self::Backspace, 'bs' => self::Backspace,
        'delete' => self::Delete, 'del' => self::Delete,
        'insert' => self::Insert, 'ins' => self::Insert,
        'up' => self::Up, 'down' => self::Down,
        'left' => self::Left, 'right' => self::Right,
        'home' => self::Home, 'end' => self::End,
        'page_up' => self::PageUp, 'pgup' => self::PageUp, 'pageup' => self::PageUp,
        'page_down' => self::PageDown, 'pgdn' => self::PageDown, 'pagedown' => self::PageDown,
        'f1' => self::F1, 'f2' => self::F2, 'f3' => self::F3, 'f4' => self::F4,
        'f5' => self::F5, 'f6' => self::F6, 'f7' => self::F7, 'f8' => self::F8,
        'f9' => self::F9, 'f10' => self::F10, 'f11' => self::F11, 'f12' => self::F12,
    ];
}
