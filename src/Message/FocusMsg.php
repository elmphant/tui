<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

/**
 * Sent only on a move: a widget that already holds the keyboard must never see
 * one, or a pane walks back to its first child between keystrokes. The terminal
 * window's own focus is TerminalFocusMsg.
 */
final readonly class FocusMsg implements Message
{
    public function __construct(public bool $focused)
    {
    }
}
