<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

/**
 * The window, not the component — where the keyboard is inside the app is FocusMsg.
 */
final readonly class TerminalFocusMsg implements Message
{
    public function __construct(public bool $focused)
    {
    }
}
