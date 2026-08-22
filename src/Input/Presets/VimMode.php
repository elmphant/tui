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
            Actions::CURSOR_DOWN => ['j'],
            Actions::CURSOR_UP => ['k'],
            Actions::PAGE_UP => ['ctrl+u'],
            Actions::PAGE_DOWN => ['ctrl+d'],
            Actions::TAB_NEXT => ['l'],
            Actions::TAB_PREVIOUS => ['h'],
            Actions::SCROLL_UP => ['k'],
            Actions::SCROLL_DOWN => ['j'],
        ]);
    }
}
