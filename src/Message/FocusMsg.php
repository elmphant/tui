<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\FocusMove;

/**
 * Sent only on a move: a widget that already holds the keyboard must never see
 * one, or a pane walks back to its first child between keystrokes. The terminal
 * window's own focus is TerminalFocusMsg.
 *
 * `move` is the end the keyboard came in at, which only the ring walker knows.
 */
final readonly class FocusMsg implements Message
{
    public function __construct(
        public FocusMove $move = FocusMove::To,
    ) {
    }
}
