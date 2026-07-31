<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input\Presets;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;

final class VimMode
{
    public static function keybindings(): Keybindings
    {
        static $instance = null;

        return $instance ??= new Keybindings([
            Actions::FOCUS_NEXT => ['j'],
            Actions::FOCUS_PREV => ['k'],
            Actions::PAGE_UP => ['ctrl+u'],
            Actions::PAGE_DOWN => ['ctrl+d'],
            Actions::TAB_NEXT => ['l'],
            Actions::TAB_PREV => ['h'],
            Actions::SCROLL_UP => ['k'],
            Actions::SCROLL_DOWN => ['j'],
        ]);
    }
}
