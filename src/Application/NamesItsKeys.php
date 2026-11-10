<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Elmphant\Tui\Input\KeyHint;

/**
 * Include a mounted model's own key bindings in help while it receives keys.
 */
interface NamesItsKeys
{
    /**
     * Derive them from active bindings with `Keybindings::keyHints()`.
     *
     * @return list<KeyHint>
     */
    public function ownKeys(): array;
}
