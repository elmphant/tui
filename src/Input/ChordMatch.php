<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

/**
 * @internal parser state exposed only between keybinding implementation objects
 */
enum ChordMatch
{
    case Complete;
    case Prefix;
    case None;
}
