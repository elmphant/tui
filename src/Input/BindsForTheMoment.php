<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

/**
 * A widget with keys that mean something only in the state it is in now, such as
 * Enter handing the keys back from a search. KeybindingsAwareBehavior adds them
 * to the defaults, so a key help and a footer list them only while they work.
 */
interface BindsForTheMoment
{
    public function momentaryKeybindings(): Keybindings;
}
