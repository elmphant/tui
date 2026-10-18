<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

/**
 * While this answers true, an unmodified printable key is text for this widget:
 * it reaches the widget before any application shortcut, and a global binding on
 * such a key does not apply to it. Modified and special keys stay shortcuts.
 */
interface TakesText
{
    public function takesText(): bool;
}
