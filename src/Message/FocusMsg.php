<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

/**
 * The keyboard arrived at, or left, the component this is delivered to.
 *
 * Sent only on a move, so a widget that already holds the keyboard never sees
 * one — that is what stops a pane walking back to its first child between
 * keystrokes. The terminal window's own focus is TerminalFocusMsg.
 */
final readonly class FocusMsg implements Message
{
    public function __construct(public bool $focused)
    {
    }
}
