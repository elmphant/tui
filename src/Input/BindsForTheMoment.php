<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

/**
 * Add state-dependent bindings to defaults so help and hints list them only when active.
 */
interface BindsForTheMoment
{
    public function momentaryKeybindings(): Keybindings;
}
