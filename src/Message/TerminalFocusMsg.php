<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

/**
 * The terminal window gained or lost focus. Where the keyboard is inside the
 * application is FocusMsg.
 */
final readonly class TerminalFocusMsg implements Message
{
    public function __construct(public bool $focused)
    {
    }
}
